#!/usr/bin/env sh

set -eu

cd "$(dirname "$0")"

# A source build is for contributors working on the application itself. Everyone else
# runs the published image, which is what makes an install a download rather than a
# compile. The locally built image is tagged `pulllens:source`, so it can never shadow
# or be overwritten by a published tag.
from_source=0
https_only=0
https_domain=""
https_email=""
while [ $# -gt 0 ]; do
    case "$1" in
        --from-source|--build)
            from_source=1
            ;;
        --https)
            https_only=1
            ;;
        -h|--help)
            printf 'Usage: %s [--from-source] [--https] [domain] [email]\n\n' "$0"
            printf '  --from-source  Build the image from this checkout instead of\n'
            printf '                 pulling the published one. For contributors.\n'
            printf '  --https        Only set up HTTPS on an instance that is already\n'
            printf '                 installed, then stop.\n\n'
            printf '  A domain and email may be given to answer the HTTPS questions\n'
            printf '  without prompting, for example:\n'
            printf '    %s --https pulllens.example.com you@example.com\n' "$0"
            exit 0
            ;;
        -*)
            printf 'Unknown option: %s\n' "$1" >&2
            exit 1
            ;;
        *)
            # Positional: the domain, then the contact email for the certificate.
            if [ -z "$https_domain" ]; then
                https_domain="$1"
            elif [ -z "$https_email" ]; then
                https_email="$1"
            else
                printf 'Unexpected argument: %s\n' "$1" >&2
                exit 1
            fi
            ;;
    esac
    shift
done

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

random_secret() {
    openssl rand -base64 48 | tr -dc 'a-zA-Z0-9' | head -c 32
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

is_full_url() {
    case "$1" in
        http://*.*|https://*.*) return 0 ;;
        *) return 1 ;;
    esac
}

ensure_app_url() {
    current_app_url="$(env_value APP_URL)"

    if [ "$current_app_url" != "http://localhost" ]; then
        success "APP_URL is already set to $current_app_url."
        return
    fi

    if [ ! -t 0 ]; then
        warn "APP_URL is still http://localhost. Non-interactive shell detected, so URL prompt was skipped."
        return
    fi

    section "Website URL"
    warn "APP_URL is still set to the default: http://localhost"

    while :; do
        printf 'Enter your website base URL, for example https://example.com, or press Enter to skip: '
        IFS= read -r new_app_url

        if [ -z "$new_app_url" ]; then
            warn "Keeping APP_URL=http://localhost. You can change it later in .env and rerun ./install.sh."
            return
        fi

        if is_full_url "$new_app_url"; then
            replace_env_value APP_URL "$new_app_url"
            replace_env_value ASSET_URL '"${APP_URL}"'
            success "APP_URL set to $new_app_url."
            return
        fi

        fail "Invalid URL. Use a full URL like https://example.com."
    done
}

ensure_session_cookie_security() {
    # A Secure session cookie is discarded by the browser when the page arrived over
    # plain http://, which loses the session and the CSRF token and turns every POST -
    # the login form first - into a 419. Keep the flag in step with the address the
    # instance is actually served on.
    app_url="$(env_value APP_URL)"

    case "$app_url" in
        https://*) desired_secure_cookie="true" ;;
        *) desired_secure_cookie="false" ;;
    esac

    if [ "$(env_value SESSION_SECURE_COOKIE)" = "$desired_secure_cookie" ]; then
        success "SESSION_SECURE_COOKIE is already $desired_secure_cookie for $app_url."
    else
        info "Setting SESSION_SECURE_COOKIE=$desired_secure_cookie to match $app_url."
        replace_env_value SESSION_SECURE_COOKIE "$desired_secure_cookie"
    fi

    case "$app_url" in
        https://*|http://localhost|http://localhost:*|http://127.0.0.1|http://127.0.0.1:*) ;;
        *)
        warn "$app_url is not HTTPS, so the session cookie cannot be marked Secure."
        warn "Sessions will travel in cleartext and can be stolen by anyone on the network path."
        warn "Serve PullLens over HTTPS - a reverse proxy terminating TLS is enough - and rerun ./install.sh."
        ;;
    esac
}

ensure_admin_credentials() {
    # The seeder reads the bootstrap administrator from the environment, never from
    # source. Make sure both values exist before anything tries to seed.
    if [ -z "$(env_value ADMIN_EMAIL)" ]; then
        info "Setting ADMIN_EMAIL to admin@pulllens.local."
        replace_env_value ADMIN_EMAIL "admin@pulllens.local"
    else
        success "ADMIN_EMAIL is already set."
    fi

    if [ -z "$(env_value ADMIN_NAME)" ]; then
        replace_env_value ADMIN_NAME '"Administrator"'
    fi

    if [ -z "$(env_value ADMIN_PASSWORD)" ]; then
        info "Generating ADMIN_PASSWORD."
        replace_env_value ADMIN_PASSWORD "$(random_secret)"
    else
        success "ADMIN_PASSWORD is already set."
    fi
}

ensure_docker() {
    section "Checking Docker"

    if command_exists docker && docker compose version >/dev/null 2>&1; then
        success "Docker and Docker Compose are available."
        return
    fi

    if ! command_exists curl; then
        fail "curl is required to install Docker. Install curl and re-run this script."
        exit 1
    fi

    warn "Docker or Docker Compose was not found. Installing Docker now."
    curl -sSL https://get.docker.com | sh

    if command_exists systemctl; then
        info "Starting Docker with systemctl."
        systemctl enable --now docker >/dev/null 2>&1 || true
    elif command_exists service; then
        info "Starting Docker service."
        service docker start >/dev/null 2>&1 || true
    fi

    if ! docker compose version >/dev/null 2>&1; then
        fail "Docker was installed, but 'docker compose' is not available. Re-open your shell or check Docker."
        exit 1
    fi

    success "Docker installation is ready."
}

ensure_env() {
    section "Preparing Environment"

    if [ ! -f .env ]; then
        info "Creating .env from .env.example."
        cp .env.example .env
    else
        success ".env already exists."
    fi

    if ! command_exists openssl; then
        fail "openssl is required to generate secure secrets. Install openssl and re-run this script."
        exit 1
    fi

    if ! grep -q '^APP_KEY=.\+' .env; then
        info "Generating APP_KEY."
        replace_env_value APP_KEY "$(random_secret)"
    else
        success "APP_KEY is already set."
    fi

    ensure_app_url
    ensure_session_cookie_security
    ensure_admin_credentials

    # The image tag follows this checkout, not a hand-edited .env. compose.yml and the
    # Nginx config ship in the clone while the application ships in the image, so the
    # two have to describe the same release; deriving the tag from VERSION is what
    # keeps them together. Pin a release by checking out its tag and rerunning.
    if [ "$from_source" -eq 1 ]; then
        info "Building from this checkout; the image will be tagged pulllens:source."
        replace_env_value PULLLENS_IMAGE "pulllens"
        replace_env_value PULLLENS_VERSION "source"
        replace_env_value PULLLENS_PULL_POLICY "build"

        # Only a source build honours these: they are Dockerfile build args.
        current_uid="$(id -u)"
        current_gid="$(id -g)"
        info "Setting UID=$current_uid and GID=$current_gid for the build."
        replace_env_value UID "$current_uid"
        replace_env_value GID "$current_gid"
    else
        release="$(tr -d ' \n' < VERSION)"
        info "Running the published image vlancy/pulllens:$release."
        replace_env_value PULLLENS_IMAGE "vlancy/pulllens"
        replace_env_value PULLLENS_VERSION "$release"
        replace_env_value PULLLENS_PULL_POLICY "missing"
    fi

    changed_placeholders=0
    tmp_env="$(mktemp)"
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            *=change-me|*=\"change-me\"|*=\'change-me\')
                key="${line%%=*}"
                changed_placeholders=1
                printf '%s=%s\n' "$key" "$(random_secret)"
                ;;
            *)
                printf '%s\n' "$line"
                ;;
        esac
    done < .env > "$tmp_env"

    mv "$tmp_env" .env

    if [ "$changed_placeholders" -eq 1 ]; then
        success "Replaced placeholder change-me secrets."
    else
        success "No placeholder change-me secrets found."
    fi
}

check_service_running() {
    service="$1"

    if docker compose ps --services --status running | grep -qx "$service"; then
        success "$service is running."
        return
    fi

    fail "$service is not running."
    docker compose ps
    exit 1
}

refresh_nginx_upstream() {
    # The Nginx config now resolves the app container through Docker's DNS on a timer,
    # so a changed address heals itself within seconds. This restart makes that
    # immediate rather than eventual, and covers an operator whose clone still has the
    # old config. Cheap and idempotent, so it runs on every install.
    info "Restarting Nginx so it picks up the app container's current address."
    docker compose restart nginx
    success "Nginx is pointed at the running app container."
}

verify_assets_published() {
    section "Checking Published Assets"

    # The assets service copies the image's public/ into the volume Nginx serves and
    # then exits, so `ps --status running` will never show it. Its exit code is the
    # only evidence that Nginx has a document root at all.
    assets_exit="$(docker inspect -f '{{.State.ExitCode}}' pulllens-assets 2>/dev/null || echo missing)"

    if [ "$assets_exit" != "0" ]; then
        fail "The asset publishing step did not finish cleanly (exit: $assets_exit)."
        docker compose logs assets
        exit 1
    fi

    success "Frontend assets were published to the Nginx volume."
}

prune_legacy_images() {
    # Only once everything has passed. At this point nothing references the images the
    # old build-on-the-server layout produced, and they are well over a gigabyte.
    for legacy in pulllens-app:latest pulllens-nginx:latest; do
        if docker image inspect "$legacy" >/dev/null 2>&1; then
            info "Removing the superseded local image $legacy."
            docker image rm "$legacy" >/dev/null 2>&1 \
                || warn "Could not remove $legacy. Remove it by hand when convenient."
        fi
    done
}

verify_containers() {
    section "Checking Containers"

    check_service_running nginx
    check_service_running app
    check_service_running horizon
    check_service_running scheduler
    check_service_running pgsql
    check_service_running redis
    check_service_running soketi
}

verify_application() {
    section "Checking Application"

    docker compose exec -T app test -f vendor/autoload.php
    success "Composer vendor files are present."

    docker compose exec -T app test -d public/build
    success "Frontend build assets are present in the application image."

    docker compose exec -T nginx test -d /var/www/html/public/build
    success "Frontend build assets are present in the Nginx volume."

    # The volume outlives an upgrade, so "a build directory exists" is exactly what a
    # stale one would also satisfy. Identical manifests prove the tree Nginx serves is
    # the tree the running PHP expects.
    app_manifest="$(docker compose exec -T app sh -c 'cat public/build/manifest.json public/build/.vite/manifest.json 2>/dev/null | md5sum' | cut -d' ' -f1)"
    web_manifest="$(docker compose exec -T nginx sh -c 'cat /var/www/html/public/build/manifest.json /var/www/html/public/build/.vite/manifest.json 2>/dev/null | md5sum' | cut -d' ' -f1)"

    if [ "$app_manifest" != "$web_manifest" ]; then
        fail "Nginx is serving a different asset build than the application expects."
        fail "Run: docker compose up -d --force-recreate assets"
        exit 1
    fi
    success "Nginx and the application agree on the asset build."

    docker compose exec -T app php artisan --version
    success "Laravel boot check passed."

    docker compose exec -T nginx wget -qO- http://127.0.0.1/up >/dev/null
    success "HTTP health check passed."

    docker compose exec -T nginx wget -qO- http://127.0.0.1/login >/dev/null
    success "Login page check passed."
}

print_ready_message() {
    users_existed_before_seed="$1"
    app_url="$(env_value APP_URL)"
    login_url="${app_url%/}/login"
    initial_email="$(env_value ADMIN_EMAIL)"
    initial_password="$(env_value ADMIN_PASSWORD)"

    if [ "$users_existed_before_seed" != "yes" ] && { [ -z "$initial_email" ] || [ -z "$initial_password" ]; }; then
        fail "Could not read ADMIN_EMAIL / ADMIN_PASSWORD from .env."
        exit 1
    fi

    section "System Ready"
    success "PullLens is ready to use."
    printf '%sAPP URL:%s %s\n' "$bold" "$reset" "$app_url"
    printf '%sLogin URL:%s %s\n' "$bold" "$reset" "$login_url"

    if [ "$users_existed_before_seed" = "yes" ]; then
        printf '%sLogin:%s Existing users were detected. Use your existing admin account.\n' "$bold" "$reset"
    else
        printf '%sInitial user email:%s %s\n' "$bold" "$reset" "$initial_email"
        printf '%sInitial user password:%s %s\n' "$bold" "$reset" "$initial_password"
        printf '%sNote:%s These are the initial first-install credentials. Log in with them, then you can change them from the application.\n' "$bold" "$reset"
    fi
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

https_check_port() {
    # Nginx has to answer on 80 for the ACME challenge and on 443 afterwards. If the
    # stack has been moved off port 80, something else on this host owns it.
    app_port="$(env_value_or_default APP_PORT 80)"

    if [ "$app_port" != "80" ]; then
        fail "APP_PORT is $app_port, not 80."
        fail "Let's Encrypt validates over port 80, so the stack has to own it."
        info "Free port 80 on this host, set APP_PORT=80 in .env, rerun this script."
        return 1
    fi
}

https_ask_domain() {
    section "Domain"

    while :; do
        if [ -z "$https_domain" ]; then
            current_url="$(env_value APP_URL)"
            if [ -n "$current_url" ]; then
                info "APP_URL is currently $current_url."
            fi
            printf 'Domain that points at this server (for example pulllens.example.com): '
            read -r https_domain || https_domain=""
        fi

        # Accept a pasted URL as well as a bare hostname; people copy the address bar.
        https_domain="$(printf '%s' "$https_domain" | sed 's#^https\{0,1\}://##; s#/.*$##')"

        if is_domain_name "$https_domain"; then
            break
        fi

        fail "Not a domain Let's Encrypt can issue for: '$https_domain'."
        fail "It needs a real hostname with a dot, not an IP address or localhost."
        https_domain=""

        [ -t 0 ] || return 1
    done

    success "Using $https_domain."
}

https_ask_email() {
    [ -n "$https_email" ] && return 0
    [ -t 0 ] || return 0

    section "Contact Email"
    info "Let's Encrypt uses this only to warn you if a renewal is failing."
    printf 'Email address (blank to register without one): '
    read -r https_email || https_email=""
}

https_check_dns() {
    section "Checking DNS"

    # Requesting a certificate for a domain that does not resolve here is the most
    # common way this fails, and it fails after a rate-limited attempt. Warn first.
    resolved=""
    if command_exists getent; then
        resolved="$(getent ahostsv4 "$https_domain" 2>/dev/null | awk 'NR==1{print $1}')"
    fi
    if [ -z "$resolved" ] && command_exists dig; then
        resolved="$(dig +short A "$https_domain" 2>/dev/null | head -n 1)"
    fi

    if [ -z "$resolved" ]; then
        warn "$https_domain does not resolve yet. Issuance will fail until it does."
        [ -t 0 ] || return 1
        printf 'Continue anyway? [y/N]: '
        read -r answer || answer=""
        case "$answer" in
            [yY]|[yY][eE][sS]) return 0 ;;
            *) info "Stopped. Point the domain at this server and try again."; return 1 ;;
        esac
    fi

    public_ip=""
    if command_exists curl; then
        public_ip="$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)"
    fi

    if [ -n "$public_ip" ] && [ "$resolved" != "$public_ip" ]; then
        warn "$https_domain resolves to $resolved but this server appears to be $public_ip."
        warn "If a proxy such as Cloudflare is in front, that is expected - but then the"
        warn "challenge cannot reach this server. Stop, and set APP_URL to the https://"
        warn "address your visitors already use instead."
        [ -t 0 ] || return 1
        printf 'Continue anyway? [y/N]: '
        read -r answer || answer=""
        case "$answer" in
            [yY]|[yY][eE][sS]) return 0 ;;
            *) info "Stopped."; return 1 ;;
        esac
    else
        success "$https_domain resolves to this server."
    fi
}

https_request_certificate() {
    section "Requesting The Certificate"

    if docker compose run --rm --entrypoint sh certbot -c "test -d /etc/letsencrypt/live/$https_domain" >/dev/null 2>&1; then
        success "A certificate for $https_domain already exists and will renew itself."
        return 0
    fi

    email_args="--register-unsafely-without-email"
    if [ -n "$https_email" ]; then
        email_args="--email $https_email"
    fi

    # --webroot writes the challenge into the volume Nginx already serves at
    # /.well-known/acme-challenge/, so nothing stops and no port has to be freed.
    if ! docker compose run --rm --entrypoint certbot certbot \
        certonly --webroot -w /var/www/certbot \
        -d "$https_domain" \
        $email_args \
        --agree-tos --no-eff-email --non-interactive; then
        fail "Certificate issuance failed."
        info "The usual causes, in order:"
        info "  - $https_domain does not point at this server"
        info "  - port 80 is not reachable from the internet (firewall, security group)"
        info "  - a proxy such as Cloudflare answers first, so the challenge never arrives"
        info "Nothing has changed. PullLens is still serving over plain HTTP."
        return 1
    fi

    success "Certificate issued for $https_domain."
}

https_write_config() {
    section "Configuring Nginx"

    # Generated rather than tracked: it names one operator's domain, and a tracked
    # file that every install rewrites would make `git pull` refuse to fast-forward.
    cat > docker/config/nginx/tls.conf <<EOF
# Generated by install.sh for $https_domain. Edits are overwritten on the next run.
#
# These servers name the domain explicitly, so Nginx prefers them over the catch-all
# in default.conf. Anything arriving by another name, or by bare IP, still lands on
# the plain-HTTP server there.

server {
    listen 80;
    server_name $https_domain;

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
    server_name $https_domain;

    ssl_certificate     /etc/letsencrypt/live/$https_domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$https_domain/privkey.pem;

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

https_update_app_url() {
    section "Pointing The Application At HTTPS"

    replace_env_value APP_URL "https://$https_domain"
    replace_env_value ASSET_URL '"${APP_URL}"'

    # A browser discards a Secure cookie that arrived over plain http:// and keeps one
    # that arrived over https://. Now that the address is https://, this goes back on
    # or the session travels in the clear.
    replace_env_value SESSION_SECURE_COOKIE "true"
    replace_env_value PUSHER_SCHEME "https"
    replace_env_value PUSHER_PORT "443"
    replace_env_value VITE_PUSHER_SCHEME "https"
    replace_env_value VITE_PUSHER_PORT "443"

    success "APP_URL is now https://$https_domain."
}

https_restart() {
    section "Restarting"

    # No rebuild. The compiled frontend contains no address - asset() resolves APP_URL
    # in PHP on every request - so recreating the containers so they read the new
    # environment, and rebuilding the config cache, is the whole job.
    docker compose up -d
    docker compose restart nginx
    docker compose exec -T app php artisan optimize \
        || warn "Could not rebuild the Laravel caches. Rerun ./install.sh to finish."

    success "The stack is serving HTTPS."
}

https_verify() {
    section "Checking HTTPS"

    command_exists curl || { warn "curl is not available; skipping the check."; return 0; }

    code="$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 15 "https://$https_domain/up" 2>/dev/null || echo failed)"

    if [ "$code" = "200" ]; then
        success "https://$https_domain/up answered 200."
    else
        warn "https://$https_domain/up did not answer as expected (got: $code)."
        warn "Check that port 443 is open at your firewall, then try again in a minute."
    fi
}

https_summary() {
    section "HTTPS Ready"
    success "PullLens is served over HTTPS from inside the stack."
    printf '%sApp URL:%s     https://%s\n' "$bold" "$reset" "$https_domain"
    printf '%sLogin URL:%s   https://%s/login\n' "$bold" "$reset" "$https_domain"
    printf '%sCertificate:%s renewed automatically by the pulllens-certbot container\n' "$bold" "$reset"
    printf '%sCheck it:%s    docker compose logs certbot\n' "$bold" "$reset"
    printf '\n'
    info "Port 80 stays open on purpose: it redirects to HTTPS and carries the renewal"
    info "challenge every 60 days. Closing it breaks renewal."
}

setup_https() {
    https_check_port || return 1
    https_ask_domain || return 1
    https_ask_email
    https_check_dns || return 1
    https_request_certificate || return 1
    https_write_config
    https_update_app_url
    https_restart
    https_verify
    https_summary
}

offer_https_setup() {
    # HTTPS is optional and always the last thing that happens: the stack is already
    # verified and printed above, so declining changes nothing.
    case "$(env_value APP_URL)" in
        https://*) return ;;
    esac

    if [ ! -t 0 ]; then
        info "Non-interactive shell, so the HTTPS question was skipped. Run ./install.sh --https to add a certificate."
        return
    fi

    section "HTTPS"
    info "PullLens is reachable over plain HTTP right now. Sessions travel in the"
    info "clear over plain HTTP, so this is a state to pass through, not settle in."
    printf '\n'
    info "This puts the certificate in the stack itself: Nginx takes ports 80 and 443"
    info "on this server and a certbot container keeps it renewed. You need a domain"
    info "already pointing here."
    printf '\n'
    warn "Say no if something already terminates HTTPS in front of PullLens -"
    warn "Cloudflare's proxy, a load balancer, or another web server on this host."
    warn "Requesting a certificate would fail. Set APP_URL to the https:// address"
    warn "your visitors already use and rerun ./install.sh instead."
    printf '\n'
    printf 'Set up HTTPS now? [y/N]: '
    IFS= read -r https_answer

    case "$https_answer" in
        y|Y|yes|YES|Yes)
            setup_https || warn "HTTPS setup did not finish. PullLens is still running over HTTP; rerun ./install.sh --https to try again."
            ;;
        *)
            warn "Skipped. Run ./install.sh --https whenever you are ready."
            ;;
    esac
}

# --https is for an instance that is already installed and only needs a certificate.
# Running the whole installer would work, but it would pull, migrate and reseed to
# reach a step that touches none of that.
if [ "$https_only" -eq 1 ]; then
    section "PullLens HTTPS"

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

    setup_https
    exit $?
fi

section "PullLens Installer"
info "This script is safe to re-run. Existing secrets are preserved."
info "The application runs the published vlancy/pulllens image. Use --from-source to build this checkout instead."

ensure_docker
ensure_env

section "Updating Source Code"
info "Pulling latest code from Git."
git pull --ff-only
success "Source code is up to date."

if [ "$from_source" -eq 1 ]; then
    section "Building And Starting Containers"
    info "Building the image from this checkout."
    docker compose up -d --build --remove-orphans
else
    section "Fetching The PullLens Image"
    # Pulling before anything is stopped is the safety property of an upgrade: if the
    # registry is unreachable or rate-limiting, this fails here and the stack that is
    # already serving has not been touched.
    info "Pulling the application image and the supporting images."
    docker compose pull --quiet
    success "Images are up to date."

    section "Starting Containers"
    # --remove-orphans clears the pulllens-nginx container from the old layout, which
    # built its own image and no longer has a service to belong to.
    docker compose up -d --remove-orphans
fi
success "Containers are running."

refresh_nginx_upstream

verify_assets_published

verify_containers

section "Preparing Laravel"
info "Clearing old Laravel caches."
docker compose exec -T app php artisan optimize:clear

info "Running database migrations."
docker compose exec -T app php artisan migrate --force

info "Checking whether users already exist."
users_existed_before_seed="$(docker compose exec -T app php artisan pulllens:users-exist | tr -d '\r\n')"

if [ "$users_existed_before_seed" = "yes" ]; then
    success "Existing users detected. Initial admin seeding will be skipped."
else
    success "No users detected. Initial admin user will be created."
fi

info "Running database seeders."
docker compose exec -T app php artisan db:seed --force || warn "Database seeders failed or may already be applied; continuing."

info "Creating storage symlink if needed."
docker compose exec -T app php artisan storage:link || true

info "Building optimized Laravel caches."
docker compose exec -T app php artisan optimize

info "Restarting queue workers if running."
docker compose exec -T app php artisan queue:restart || true

info "Restarting Horizon workers if running."
docker compose exec -T app php artisan horizon:terminate || true

verify_application

prune_legacy_images

print_ready_message "$users_existed_before_seed"

offer_https_setup
