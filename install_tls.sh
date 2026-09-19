#!/usr/bin/env sh
#
# PullLens in-container HTTPS.
#
# Terminates TLS inside the Docker stack: Nginx takes ports 80 and 443 itself, a
# certbot container issues and renews a free Let's Encrypt certificate, and the
# application is pointed at the new https:// address.
#
# Use this when the server is PullLens's alone. If something else already terminates
# TLS in front of it - Cloudflare, a company load balancer, or a host Nginx serving
# other sites - do NOT run this: the certificate request would fail, and you only need
# to set APP_URL to the https:// address the outside world uses. ./install_ssl.sh
# remains available for the host-Nginx arrangement.
#
# Safe to re-run. An existing certificate that is still valid is kept, the Nginx
# configuration is written from the same template every time, and renewal is handled
# continuously by the certbot container rather than by this script.
#
# Usage:
#   ./install_tls.sh                     interactive
#   ./install_tls.sh example.com         domain given, email prompted
#   ./install_tls.sh example.com me@x.io fully non-interactive

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
    [ -f .env ] || return 0
    sed -n "s/^$1=//p" .env | head -n 1 | sed 's/^"//; s/"$//'
}

is_domain_name() {
    case "$1" in
        ''|*://*|*/*|*:*|*' '*) return 1 ;;
        .*|*.) return 1 ;;
        localhost|*.local|*.localhost) return 1 ;;
    esac

    # Let's Encrypt does not issue for a bare IP address, so reject one here rather
    # than at the end of a certbot run.
    case "$1" in
        *[!0-9.]*) ;;
        *) return 1 ;;
    esac

    case "$1" in
        *.*) return 0 ;;
        *) return 1 ;;
    esac
}

domain="${1:-}"
email="${2:-}"

ensure_prerequisites() {
    section "Checking Prerequisites"

    if ! command_exists docker; then
        fail "Docker was not found. Run ./install.sh first."
        exit 1
    fi

    if [ ! -f .env ]; then
        fail "There is no .env yet. Run ./install.sh first."
        exit 1
    fi

    if ! docker compose ps --status running --services 2>/dev/null | grep -q '^nginx$'; then
        fail "The PullLens stack is not running. Run ./install.sh first."
        exit 1
    fi

    success "Docker is available and the stack is running."
}

explain_and_confirm() {
    section "HTTPS Inside The Stack"

    printf 'This puts the certificate in the PullLens stack itself. Nginx will take\n'
    printf 'ports 80 and 443 on this server, and a certbot container will keep the\n'
    printf 'certificate renewed.\n\n'

    printf '%sDo not use this if something else already terminates HTTPS%s - Cloudflare\n' "$bold" "$reset"
    printf 'with its proxy on, a load balancer, or an Nginx on this host serving other\n'
    printf 'sites. In that case press N: set APP_URL to the https:// address your\n'
    printf 'visitors use and you are done, or use ./install_ssl.sh for a host proxy.\n\n'

    printf 'Set up HTTPS inside the stack now? [y/N]: '
    read -r answer || answer=""

    case "$answer" in
        [yY]|[yY][eE][sS]) ;;
        *)
            info "Skipped. PullLens keeps serving over plain HTTP."
            info "If HTTPS is terminated elsewhere, set APP_URL to that https:// address."
            exit 0
            ;;
    esac
}

ensure_ports_are_free() {
    # Nginx has to answer on 80 for the ACME challenge and on 443 afterwards. If the
    # stack has been moved off port 80 - which ./install_ssl.sh does, so a host proxy
    # can take it - then this is the wrong script for this server.
    app_port="$(env_value APP_PORT)"
    app_port="${app_port:-80}"

    if [ "$app_port" != "80" ]; then
        section "Port Conflict"
        fail "APP_PORT is $app_port, not 80."
        fail "Let's Encrypt validates over port 80, so the stack must own it."
        printf '\n'
        info "This usually means ./install_ssl.sh already put an Nginx on the host in"
        info "front of the stack. Keep using that, or set APP_PORT=80 in .env, remove"
        info "the host proxy, run ./install.sh, then run this script again."
        exit 1
    fi
}

ask_for_domain() {
    section "Domain"

    current_url="$(env_value APP_URL)"

    while :; do
        if [ -n "$domain" ]; then
            candidate="$domain"
        else
            if [ -n "$current_url" ]; then
                info "APP_URL is currently $current_url."
            fi
            printf 'Domain that points at this server (for example pulllens.example.com): '
            read -r candidate || candidate=""
        fi

        # Accept a pasted URL as well as a bare hostname; people copy the address bar.
        candidate="$(printf '%s' "$candidate" | sed 's#^https\{0,1\}://##; s#/.*$##')"

        if is_domain_name "$candidate"; then
            domain="$candidate"
            break
        fi

        fail "That is not a domain Let's Encrypt can issue for: '$candidate'."
        fail "It needs a real hostname with a dot, not an IP address or localhost."
        domain=""

        if [ ! -t 0 ]; then
            exit 1
        fi
    done

    success "Using $domain."
}

ask_for_email() {
    if [ -n "$email" ]; then
        return
    fi

    section "Contact Email"
    info "Let's Encrypt uses this only to warn you if a renewal is failing."
    printf 'Email address (blank to register without one): '
    read -r email || email=""
}

check_dns_points_here() {
    section "Checking DNS"

    # A certificate request for a domain that does not resolve here is the single most
    # common way this fails, and it fails after a rate-limited attempt. Warn first.
    if ! command_exists getent && ! command_exists nslookup && ! command_exists dig; then
        warn "No DNS lookup tool available; skipping the check."
        return
    fi

    resolved=""
    if command_exists getent; then
        resolved="$(getent ahostsv4 "$domain" 2>/dev/null | awk 'NR==1{print $1}')"
    fi
    if [ -z "$resolved" ] && command_exists dig; then
        resolved="$(dig +short A "$domain" 2>/dev/null | head -n 1)"
    fi

    if [ -z "$resolved" ]; then
        warn "$domain does not resolve yet. Certificate issuance will fail until it does."
        printf 'Continue anyway? [y/N]: '
        read -r answer || answer=""
        case "$answer" in
            [yY]|[yY][eE][sS]) return ;;
            *) info "Stopped. Point the domain at this server and run this script again."; exit 0 ;;
        esac
    fi

    public_ip=""
    if command_exists curl; then
        public_ip="$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)"
    fi

    if [ -n "$public_ip" ] && [ "$resolved" != "$public_ip" ]; then
        warn "$domain resolves to $resolved but this server appears to be $public_ip."
        warn "If Cloudflare's proxy is on, that is expected - but then the challenge"
        warn "will fail and you should press N and use Cloudflare's own certificate."
        printf 'Continue anyway? [y/N]: '
        read -r answer || answer=""
        case "$answer" in
            [yY]|[yY][eE][sS]) ;;
            *) info "Stopped."; exit 0 ;;
        esac
    else
        success "$domain resolves to this server."
    fi
}

request_certificate() {
    section "Requesting The Certificate"

    if docker compose run --rm --entrypoint sh certbot -c "test -d /etc/letsencrypt/live/$domain" >/dev/null 2>&1; then
        success "A certificate for $domain already exists; it will be renewed automatically."
        return
    fi

    email_args="--register-unsafely-without-email"
    if [ -n "$email" ]; then
        email_args="--email $email"
    fi

    # --webroot writes the challenge file into the volume Nginx already serves at
    # /.well-known/acme-challenge/, so nothing has to stop and nothing has to bind a
    # port that is already in use.
    if ! docker compose run --rm --entrypoint certbot certbot \
        certonly --webroot -w /var/www/certbot \
        -d "$domain" \
        $email_args \
        --agree-tos --no-eff-email --non-interactive; then
        fail "Certificate issuance failed."
        printf '\n'
        info "The usual causes, in order:"
        info "  - $domain does not point at this server"
        info "  - port 80 is not reachable from the internet (firewall, security group)"
        info "  - Cloudflare's proxy is on, so the challenge never reaches this server"
        info "Nothing has been changed. PullLens is still serving over plain HTTP."
        exit 1
    fi

    success "Certificate issued for $domain."
}

write_tls_config() {
    section "Configuring Nginx"

    # Written rather than tracked: it names one operator's domain, and a tracked file
    # that every install rewrites would make `git pull` refuse to fast-forward.
    cat > docker/config/nginx/tls.conf <<EOF
# Generated by install_tls.sh for $domain. Edits are overwritten on the next run.
#
# These servers name the domain explicitly, so Nginx prefers them over the catch-all
# in default.conf. Anything arriving by another name, or by bare IP, still lands on
# the plain-HTTP server there.

server {
    listen 80;
    server_name $domain;

    # Renewal re-runs the same HTTP challenge every 60 days, so this has to keep
    # working after the redirect below goes in.
    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type "text/plain";
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    http2 on;
    server_name $domain;

    ssl_certificate     /etc/letsencrypt/live/$domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$domain/privkey.pem;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:PullLensTLS:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;

    # Tells the application the request arrived over TLS, so it generates https://
    # links and keeps the Secure flag on the session cookie.
    set \$pulllens_https "on";

    include /etc/nginx/conf.d/pulllens-app.inc;
}
EOF

    success "Wrote docker/config/nginx/tls.conf."
}

update_application_url() {
    section "Pointing The Application At HTTPS"

    replace_env_value APP_URL "https://$domain"
    replace_env_value ASSET_URL '"${APP_URL}"'

    # A browser discards a Secure cookie that arrived over plain http://, and keeps one
    # that arrived over https://. Now that the address is https://, this must go back on
    # or the session is sent in the clear.
    replace_env_value SESSION_SECURE_COOKIE "true"

    replace_env_value PUSHER_SCHEME "https"
    replace_env_value PUSHER_PORT "443"
    replace_env_value VITE_PUSHER_SCHEME "https"
    replace_env_value VITE_PUSHER_PORT "443"

    success "APP_URL is now https://$domain."
}

restart_stack() {
    section "Restarting"

    # No rebuild. The compiled frontend contains no address - asset() resolves APP_URL
    # in PHP on every request - so recreating the containers so they read the new
    # environment, and rebuilding the config cache, is the whole job.
    docker compose up -d
    docker compose restart nginx
    docker compose exec -T app php artisan optimize \
        || warn "Could not rebuild the Laravel caches. Run ./install.sh to finish."

    success "The stack is serving HTTPS."
}

verify() {
    section "Checking HTTPS"

    if ! command_exists curl; then
        warn "curl is not available; skipping the check."
        return
    fi

    code="$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 15 "https://$domain/up" 2>/dev/null || echo failed)"

    if [ "$code" = "200" ]; then
        success "https://$domain/up answered 200."
    else
        warn "https://$domain/up did not answer as expected (got: $code)."
        warn "Check that port 443 is open at your firewall, then try again in a minute."
    fi
}

print_summary() {
    section "HTTPS Ready"
    success "PullLens is served over HTTPS from inside the stack."
    printf '%sApp URL:%s     https://%s\n' "$bold" "$reset" "$domain"
    printf '%sLogin URL:%s   https://%s/login\n' "$bold" "$reset" "$domain"
    printf '%sCertificate:%s renewed automatically by the pulllens-certbot container\n' "$bold" "$reset"
    printf '%sCheck it:%s    docker compose logs certbot\n' "$bold" "$reset"
    printf '\n'
    info "Port 80 stays open on purpose: it redirects to HTTPS and carries the"
    info "renewal challenge every 60 days. Closing it breaks renewal."
}

ensure_prerequisites

if [ -z "$domain" ]; then
    explain_and_confirm
fi

ensure_ports_are_free
ask_for_domain
ask_for_email
check_dns_points_here
request_certificate
write_tls_config
update_application_url
restart_stack
verify
print_summary
