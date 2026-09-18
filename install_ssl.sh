#!/usr/bin/env sh
#
# PullLens HTTPS installer.
#
# Puts an Nginx reverse proxy on the host in front of the Docker stack, issues a
# free Let's Encrypt certificate with certbot, and points the application at the
# new https:// address. Running it is optional: PullLens works over plain HTTP,
# and ./install.sh only offers this step, it never forces it.
#
# Safe to re-run. certbot keeps a certificate that is still valid, and the proxy
# configuration is written from the same template every time.
#
# Usage:
#   ./install_ssl.sh                     interactive
#   ./install_ssl.sh example.com         domain given, email prompted
#   ./install_ssl.sh example.com me@x.io fully non-interactive

set -eu

cd "$(dirname "$0")"

if [ -t 1 ]; then
    bold="$(printf '\033[1m')"
    blue="$(printf '\033[34m')"
    green="$(printf '\033[32m')"
    yellow="$(printf '\033[33m')"
    red="$(printf '\033[31m')"
    reset="$(printf '\033[0m')"
else
    bold=""
    blue=""
    green=""
    yellow=""
    red=""
    reset=""
fi

section() {
    printf '\n%s%s%s\n' "$bold" "$1" "$reset"
}

info() {
    printf '%s[INFO]%s %s\n' "$blue" "$reset" "$1"
}

success() {
    printf '%s[OK]%s %s\n' "$green" "$reset" "$1"
}

warn() {
    printf '%s[WARN]%s %s\n' "$yellow" "$reset" "$1"
}

fail() {
    printf '%s[ERROR]%s %s\n' "$red" "$reset" "$1" >&2
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

replace_env_value() {
    key="$1"
    value="$2"
    tmp_env="$(mktemp)"

    awk -v key="$key" -v value="$value" '
        BEGIN { replaced = 0 }
        $0 ~ "^" key "=" { print key "=" value; replaced = 1; next }
        { print }
        END { if (replaced == 0) print key "=" value }
    ' .env > "$tmp_env"

    mv "$tmp_env" .env
}

env_value() {
    key="$1"

    grep "^$key=" .env 2>/dev/null \
        | tail -n 1 \
        | cut -d= -f2- \
        | sed 's/^"//; s/"$//; s/^'"'"'//; s/'"'"'$//'
}

env_value_or_default() {
    key="$1"
    default="$2"
    value="$(env_value "$key")"

    if [ -n "$value" ]; then
        printf '%s\n' "$value"
    else
        printf '%s\n' "$default"
    fi
}

# Strips scheme, path and port, so https://example.com/admin becomes example.com.
url_host() {
    printf '%s\n' "$1" \
        | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#[/?].*$##' -e 's#:[0-9]*$##'
}

is_domain_name() {
    case "$1" in
        ''|*://*|*/*|*:*|*' '*) return 1 ;;
        .*|*.) return 1 ;;
        localhost|*.local|*.localhost) return 1 ;;
    esac

    # Let's Encrypt does not issue for a bare IP address, so reject one here
    # rather than at the end of a certbot run.
    case "$1" in
        *[!0-9.]*) ;;
        *) return 1 ;;
    esac

    case "$1" in
        *.*) return 0 ;;
        *) return 1 ;;
    esac
}

ask_yes_no() {
    prompt="$1"
    default="$2"

    if [ ! -t 0 ]; then
        [ "$default" = "y" ]
        return
    fi

    if [ "$default" = "y" ]; then
        printf '%s [Y/n]: ' "$prompt"
    else
        printf '%s [y/N]: ' "$prompt"
    fi

    IFS= read -r answer

    case "$answer" in
        y|Y|yes|YES|Yes) return 0 ;;
        n|N|no|NO|No) return 1 ;;
        '') [ "$default" = "y" ] ;;
        *) [ "$default" = "y" ] ;;
    esac
}

port_in_use() {
    if command_exists ss; then
        ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$1\$"
        return
    fi

    if command_exists netstat; then
        netstat -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$1\$"
        return
    fi

    return 1
}

first_free_port() {
    candidate="$1"

    while [ "$candidate" -lt 8100 ]; do
        if ! port_in_use "$candidate"; then
            printf '%s\n' "$candidate"
            return
        fi

        candidate=$((candidate + 1))
    done

    printf '%s\n' "$1"
}

sudo_cmd=""
domain=""
server_names=""
email=""
app_port=""
soketi_port=""
restack="no"

require_linux() {
    case "$(uname -s)" in
        Linux) return 0 ;;
    esac

    warn "HTTPS setup needs a Linux host: it installs Nginx and certbot with the system package manager."
    warn "This machine runs $(uname -s), so the certificate step was skipped."
    warn "Run ./install_ssl.sh on the server that serves your domain."
    exit 0
}

require_env_file() {
    if [ -f .env ]; then
        return
    fi

    fail ".env was not found. Run ./install.sh first, then ./install_ssl.sh."
    exit 1
}

require_privileges() {
    if [ "$(id -u)" -eq 0 ]; then
        sudo_cmd=""
        return
    fi

    if command_exists sudo; then
        sudo_cmd="sudo"
        info "Not running as root, so sudo is used for package installation and Nginx configuration."
        return
    fi

    fail "Root privileges are required to install Nginx and certbot. Re-run as root, or install sudo."
    exit 1
}

confirm_setup() {
    section "HTTPS Setup"
    info "This will:"
    info "  - install Nginx and certbot on this host"
    info "  - publish the PullLens container on an internal port and proxy it from Nginx"
    info "  - request a free Let's Encrypt certificate for your domain"
    info "  - set APP_URL to the https:// address and rebuild the stack"
    warn "Ports 80 and 443 on this host must be free and reachable from the internet."

    if [ -n "$domain" ]; then
        return
    fi

    # install.sh already asked, so it sets this to avoid asking the same question twice.
    if [ "${PULLLENS_SSL_CONFIRMED:-}" = "1" ]; then
        return
    fi

    if [ ! -t 0 ]; then
        warn "Non-interactive shell and no domain argument, so HTTPS setup was skipped."
        warn "Run ./install_ssl.sh your-domain.example when you are ready."
        exit 0
    fi

    if ask_yes_no "Set up HTTPS now?" "y"; then
        return
    fi

    warn "Skipped. Run ./install_ssl.sh whenever you want a certificate."
    exit 0
}

ask_domain() {
    if [ -n "$domain" ]; then
        if is_domain_name "$domain"; then
            success "Using domain $domain."
        else
            fail "Invalid domain: $domain. Pass a bare domain name such as example.com."
            exit 1
        fi
    else
        suggested="$(url_host "$(env_value APP_URL)")"

        if ! is_domain_name "$suggested"; then
            suggested=""
        fi

        while :; do
            if [ -n "$suggested" ]; then
                printf 'Domain name for the certificate [%s]: ' "$suggested"
            else
                printf 'Domain name for the certificate, for example example.com: '
            fi

            IFS= read -r answer

            if [ -z "$answer" ]; then
                answer="$suggested"
            fi

            if is_domain_name "$answer"; then
                domain="$answer"
                break
            fi

            fail "Enter a bare domain name such as example.com, without http:// and without a path."
        done
    fi

    server_names="$domain"

    # www is only worth offering on an apex domain, and only when its DNS is the
    # caller's to answer for: certbot fails the whole request if one name misses.
    case "$domain" in
        www.*) ;;
        *.*.*) ;;
        *)
            if [ -t 0 ] && ask_yes_no "Also include www.$domain in the certificate?" "n"; then
                server_names="$domain www.$domain"
            fi
            ;;
    esac

    success "Certificate names: $server_names"
}

ask_email() {
    if [ -n "$email" ]; then
        success "Using $email for expiry notices."
        return
    fi

    suggested="$(env_value ADMIN_EMAIL)"

    case "$suggested" in
        *@pulllens.local|'') suggested="" ;;
    esac

    if [ ! -t 0 ]; then
        email="$suggested"
        return
    fi

    if [ -n "$suggested" ]; then
        printf 'Email for certificate expiry notices [%s]: ' "$suggested"
    else
        printf 'Email for certificate expiry notices, or press Enter to skip: '
    fi

    IFS= read -r answer

    if [ -z "$answer" ]; then
        answer="$suggested"
    fi

    email="$answer"

    if [ -z "$email" ]; then
        warn "No email given. Let's Encrypt cannot warn you before the certificate expires."
    fi
}

check_dns() {
    section "Checking DNS"

    resolved=""

    if command_exists getent; then
        resolved="$(getent ahostsv4 "$domain" 2>/dev/null | awk 'NR==1 {print $1}' || true)"
    fi

    if [ -z "$resolved" ]; then
        warn "Could not resolve $domain from this host. Certificate issuance will fail if the DNS record is missing."
        return
    fi

    public_ip=""

    if command_exists curl; then
        public_ip="$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)"
    fi

    if [ -z "$public_ip" ]; then
        info "$domain resolves to $resolved."
        return
    fi

    if [ "$resolved" = "$public_ip" ]; then
        success "$domain resolves to this server ($public_ip)."
        return
    fi

    warn "$domain resolves to $resolved, but this server looks like $public_ip."
    warn "Let's Encrypt validates over HTTP, so the record must point here."

    if [ -t 0 ] && ! ask_yes_no "Continue anyway?" "n"; then
        warn "Stopped before requesting a certificate. Fix the DNS record and re-run ./install_ssl.sh."
        exit 0
    fi
}

install_packages() {
    section "Installing Nginx And Certbot"

    if command_exists nginx && command_exists certbot; then
        success "Nginx and certbot are already installed."
    elif command_exists apt-get; then
        info "Installing nginx, certbot and python3-certbot-nginx with apt-get."
        $sudo_cmd env DEBIAN_FRONTEND=noninteractive apt-get update
        $sudo_cmd env DEBIAN_FRONTEND=noninteractive apt-get install -y nginx certbot python3-certbot-nginx
    elif command_exists dnf; then
        info "Installing nginx, certbot and python3-certbot-nginx with dnf."
        $sudo_cmd dnf install -y nginx certbot python3-certbot-nginx
    elif command_exists yum; then
        info "Installing nginx, certbot and python3-certbot-nginx with yum."
        $sudo_cmd yum install -y nginx certbot python3-certbot-nginx
    elif command_exists zypper; then
        info "Installing nginx, certbot and python3-certbot-nginx with zypper."
        $sudo_cmd zypper --non-interactive install nginx certbot python3-certbot-nginx
    elif command_exists pacman; then
        info "Installing nginx, certbot and certbot-nginx with pacman."
        $sudo_cmd pacman -Sy --noconfirm nginx certbot certbot-nginx
    else
        fail "No supported package manager was found (apt-get, dnf, yum, zypper, pacman)."
        fail "Install nginx and certbot with the certbot Nginx plugin, then re-run ./install_ssl.sh."
        exit 1
    fi

    if ! command_exists nginx; then
        fail "Nginx is still missing after installation."
        exit 1
    fi

    if ! command_exists certbot; then
        fail "certbot is still missing after installation."
        exit 1
    fi

    if command_exists systemctl; then
        $sudo_cmd systemctl enable --now nginx >/dev/null 2>&1 || true
    fi

    success "Nginx and certbot are ready."
}

move_app_off_public_ports() {
    section "Container Port"

    app_port="$(env_value_or_default APP_PORT 80)"
    soketi_port="$(env_value_or_default SOKETI_FORWARD_PORT 6001)"

    case "$app_port" in
        80|443)
            new_port="$(first_free_port 8080)"
            warn "The PullLens container publishes port $app_port, which the HTTPS proxy needs for itself."
            info "Moving the container to port $new_port. Nginx will forward to it."
            replace_env_value APP_PORT "$new_port"
            app_port="$new_port"
            restack="yes"
            ;;
        *)
            success "The container already publishes port $app_port, so Nginx can take 80 and 443."
            ;;
    esac
}

write_proxy_config() {
    section "Configuring Nginx"

    if [ -d /etc/nginx/sites-available ] && [ -d /etc/nginx/sites-enabled ]; then
        config_path="/etc/nginx/sites-available/pulllens.conf"
        enabled_path="/etc/nginx/sites-enabled/pulllens.conf"
    else
        config_path="/etc/nginx/conf.d/pulllens.conf"
        enabled_path=""
    fi

    tmp_conf="$(mktemp)"

    cat > "$tmp_conf" <<'NGINX_CONF'
# Managed by PullLens install_ssl.sh. Rewritten on every run of that script.
# certbot adds the TLS server block and the HTTP redirect below it.
server {
    listen 80;
    listen [::]:80;
    server_name __SERVER_NAMES__;

    client_max_body_size 50M;

    # Soketi websockets. Without these the realtime dashboard would try ws:// from
    # an https:// page, which browsers block as mixed content.
    location /app/ {
        proxy_pass http://127.0.0.1:__SOKETI_PORT__;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location /apps/ {
        proxy_pass http://127.0.0.1:__SOKETI_PORT__;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location / {
        proxy_pass http://127.0.0.1:__APP_PORT__;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_read_timeout 300s;
    }
}
NGINX_CONF

    sed -i.bak \
        -e "s/__SERVER_NAMES__/$server_names/" \
        -e "s/__APP_PORT__/$app_port/" \
        -e "s/__SOKETI_PORT__/$soketi_port/" \
        "$tmp_conf"

    rm -f "$tmp_conf.bak"

    $sudo_cmd cp "$tmp_conf" "$config_path"
    $sudo_cmd chmod 644 "$config_path"
    rm -f "$tmp_conf"

    if [ -n "$enabled_path" ]; then
        $sudo_cmd ln -sfn "$config_path" "$enabled_path"
    fi

    success "Wrote $config_path."

    if ! $sudo_cmd nginx -t; then
        fail "Nginx rejected its own configuration. Fix the reported error and re-run ./install_ssl.sh."
        exit 1
    fi

    if command_exists systemctl; then
        # A reload is a no-op if Nginx never came up, so fall back to a start.
        $sudo_cmd systemctl reload nginx || $sudo_cmd systemctl restart nginx
    else
        $sudo_cmd nginx -s reload || $sudo_cmd nginx
    fi

    success "Nginx is proxying $server_names to 127.0.0.1:$app_port."
}

open_firewall() {
    if command_exists ufw && $sudo_cmd ufw status 2>/dev/null | grep -q "Status: active"; then
        $sudo_cmd ufw allow 'Nginx Full' >/dev/null 2>&1 \
            || $sudo_cmd ufw allow 80,443/tcp >/dev/null 2>&1 \
            || true
        success "Allowed HTTP and HTTPS through ufw."
        return
    fi

    if command_exists firewall-cmd && $sudo_cmd firewall-cmd --state >/dev/null 2>&1; then
        $sudo_cmd firewall-cmd --permanent --add-service=http >/dev/null 2>&1 || true
        $sudo_cmd firewall-cmd --permanent --add-service=https >/dev/null 2>&1 || true
        $sudo_cmd firewall-cmd --reload >/dev/null 2>&1 || true
        success "Allowed HTTP and HTTPS through firewalld."
    fi
}

restart_stack_for_port_change() {
    if [ "$restack" != "yes" ]; then
        return
    fi

    if ! command_exists docker; then
        warn "Docker was not found, so the container port change in .env is not applied yet."
        return
    fi

    section "Re-publishing The Container"
    info "Restarting the stack so the container listens on port $app_port."
    docker compose up -d
    success "The container now publishes port $app_port."
}

issue_certificate() {
    section "Requesting Certificate"

    set -- --nginx --non-interactive --agree-tos --redirect --keep-until-expiring

    for name in $server_names; do
        set -- "$@" -d "$name"
    done

    if [ -n "$email" ]; then
        set -- "$@" -m "$email"
    else
        set -- "$@" --register-unsafely-without-email
    fi

    if ! $sudo_cmd certbot "$@"; then
        fail "certbot could not issue the certificate."
        fail "The usual causes are a DNS record that does not point here, or port 80 being blocked."
        fail "The site still works over HTTP. Fix the cause and re-run ./install_ssl.sh."
        exit 1
    fi

    success "Certificate issued and installed for $server_names."
}

enable_renewal() {
    section "Automatic Renewal"

    if command_exists systemctl; then
        if $sudo_cmd systemctl list-unit-files 2>/dev/null | grep -q '^certbot.timer'; then
            $sudo_cmd systemctl enable --now certbot.timer >/dev/null 2>&1 || true
            success "certbot.timer is enabled; certificates renew twice a day, unattended."
            return
        fi

        if $sudo_cmd systemctl list-unit-files 2>/dev/null | grep -q '^snap.certbot.renew.timer'; then
            success "The certbot snap renewal timer handles renewals."
            return
        fi
    fi

    if [ -f /etc/cron.d/certbot ]; then
        success "The packaged certbot cron job handles renewals."
        return
    fi

    warn "No certbot renewal timer was found. Add a daily cron entry: certbot renew --quiet"
}

update_application_url() {
    section "Pointing PullLens At HTTPS"

    replace_env_value APP_URL "https://$domain"
    replace_env_value ASSET_URL '"${APP_URL}"'
    success "APP_URL is now https://$domain."

    # The browser talks to Soketi through the same TLS proxy, so the public
    # websocket values change with the scheme. The server-side PUSHER_HOST keeps
    # pointing at the container over the internal network.
    replace_env_value VITE_PUSHER_HOST "$domain"
    replace_env_value VITE_PUSHER_PORT "443"
    replace_env_value VITE_PUSHER_SCHEME "https"
    replace_env_value SESSION_SECURE_COOKIE "true"
    success "Websocket and cookie settings updated for HTTPS."
}

rebuild_stack() {
    if ! command_exists docker; then
        warn "Docker was not found. Run ./install.sh on the server to apply the new .env values."
        return
    fi

    section "Rebuilding With The New Address"
    info "The frontend build embeds the websocket address, so the assets are rebuilt."
    docker compose up -d --build
    docker compose exec -T app php artisan optimize || warn "Could not rebuild the Laravel caches. Run ./install.sh to finish."
    success "The stack is running with the HTTPS configuration."
}

print_summary() {
    section "HTTPS Ready"
    success "PullLens is served over HTTPS."
    printf '%sApp URL:%s https://%s\n' "$bold" "$reset" "$domain"
    printf '%sLogin URL:%s https://%s/login\n' "$bold" "$reset" "$domain"
    printf '%sProxy config:%s %s\n' "$bold" "$reset" "$config_path"
    printf '%sRenewal check:%s sudo certbot renew --dry-run\n' "$bold" "$reset"
    printf '%sCertificates:%s sudo certbot certificates\n' "$bold" "$reset"
    warn "The container still publishes port $app_port on every interface. Block it at the firewall so visitors can only arrive over HTTPS."
}

if [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
    printf 'Usage: %s [domain] [email]\n' "$0"
    printf '\nSets up an Nginx reverse proxy and a Let'"'"'s Encrypt certificate for PullLens.\n'
    exit 0
fi

domain="${1:-${SSL_DOMAIN:-}}"
email="${2:-${SSL_EMAIL:-}}"

section "PullLens HTTPS Installer"

require_linux
require_env_file
require_privileges
confirm_setup
ask_domain
ask_email
move_app_off_public_ports
restart_stack_for_port_change
install_packages
write_proxy_config
open_firewall
check_dns
issue_certificate
enable_renewal
update_application_url
rebuild_stack
print_summary
