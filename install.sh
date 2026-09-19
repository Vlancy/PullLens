#!/usr/bin/env sh

set -eu

cd "$(dirname "$0")"

# A source build is for contributors working on the application itself. Everyone else
# runs the published image, which is what makes an install a download rather than a
# compile. The locally built image is tagged `pulllens:source`, so it can never shadow
# or be overwritten by a published tag.
from_source=0
while [ $# -gt 0 ]; do
    case "$1" in
        --from-source|--build)
            from_source=1
            ;;
        -h|--help)
            printf 'Usage: %s [--from-source]\n\n' "$0"
            printf '  --from-source  Build the image from this checkout instead of\n'
            printf '                 pulling the published one. For contributors.\n'
            exit 0
            ;;
        *)
            printf 'Unknown option: %s\n' "$1" >&2
            exit 1
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

offer_ssl_setup() {
    # HTTPS is optional and always the last thing that happens: the stack is
    # already verified and printed above, so declining changes nothing.
    if [ ! -f ./install_ssl.sh ] && [ ! -f ./install_tls.sh ]; then
        return
    fi

    case "$(uname -s)" in
        Linux) ;;
        *) return ;;
    esac

    case "$(env_value APP_URL)" in
        https://*)
            return
            ;;
    esac

    if [ ! -t 0 ]; then
        info "Non-interactive shell, so the HTTPS question was skipped. Run ./install_tls.sh for HTTPS inside the stack, or ./install_ssl.sh for a host proxy."
        return
    fi

    section "HTTPS"
    info "PullLens is reachable over plain HTTP right now. Sessions travel in the"
    info "clear over plain HTTP, so this is a state to pass through, not settle in."
    printf '\n'
    printf '  %s1%s  Inside the stack        the containers take ports 80 and 443 and\n' "$bold" "$reset"
    printf '                             renew the certificate themselves. Simplest,\n'
    printf '                             if this server is PullLens'"'"'s alone.\n'
    printf '  %s2%s  Nginx on this host      a host proxy in front of the stack. Choose\n' "$bold" "$reset"
    printf '                             this if the server also serves other sites.\n'
    printf '  %s3%s  Skip                    already behind Cloudflare, a load balancer\n' "$bold" "$reset"
    printf '                             or another proxy - or not ready yet.\n'
    printf '\n'
    info "Both 1 and 2 need a domain already pointing at this server."
    printf 'Which? [1/2/3, default 3]: '
    IFS= read -r ssl_answer

    case "$ssl_answer" in
        1)
            if [ ! -f ./install_tls.sh ]; then
                warn "install_tls.sh is missing from this checkout."
                return
            fi
            sh ./install_tls.sh || warn "HTTPS setup did not finish. PullLens is still running over HTTP; re-run ./install_tls.sh to try again."
            ;;
        2)
            PULLLENS_SSL_CONFIRMED=1 sh ./install_ssl.sh || warn "HTTPS setup did not finish. PullLens is still running over HTTP; re-run ./install_ssl.sh to try again."
            ;;
        *)
            warn "Skipped. Run ./install_tls.sh (in-stack) or ./install_ssl.sh (host proxy) whenever you are ready."
            info "If HTTPS is already terminated in front of PullLens, set APP_URL to that"
            info "https:// address in .env and rerun ./install.sh - nothing else is needed."
            ;;
    esac
}

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

offer_ssl_setup
