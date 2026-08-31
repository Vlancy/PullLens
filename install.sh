#!/usr/bin/env sh

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

seeder_property_value() {
    property="$1"

    grep -F "private string \$$property" database/seeders/UsersTableSeeder.php \
        | tail -n 1 \
        | cut -d= -f2- \
        | sed "s/^[[:space:]]*//; s/[[:space:]]*;[[:space:]]*$//; s/^['\"]//; s/['\"]$//"
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

    current_uid="$(id -u)"
    current_gid="$(id -g)"

    case "$(grep '^UID=' .env 2>/dev/null || true)" in
        ''|'UID='|'UID=1000')
            info "Setting UID=$current_uid."
            replace_env_value UID "$current_uid"
            ;;
        *)
            success "UID is already customized."
            ;;
    esac

    case "$(grep '^GID=' .env 2>/dev/null || true)" in
        ''|'GID='|'GID=1000')
            info "Setting GID=$current_gid."
            replace_env_value GID "$current_gid"
            ;;
        *)
            success "GID is already customized."
            ;;
    esac

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
    success "Frontend build assets are present in the app image."

    docker compose exec -T nginx test -d /var/www/html/public/build
    success "Frontend build assets are present in the Nginx image."

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
    initial_email="$(seeder_property_value adminEmail)"
    initial_password="$(seeder_property_value adminPassword)"

    if [ -z "$initial_email" ] || [ -z "$initial_password" ]; then
        fail "Could not read initial user credentials from database/seeders/UsersTableSeeder.php."
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

section "PullLens Installer"
info "This script is safe to re-run. Existing secrets are preserved."
info "Frontend assets are built inside Docker during: docker compose up -d --build."

ensure_docker
ensure_env

section "Updating Source Code"
info "Pulling latest code from Git."
git pull --ff-only
success "Source code is up to date."

section "Building And Starting Containers"
info "Building Docker images and starting containers."
docker compose up -d --build
success "Containers are built and running."

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

print_ready_message "$users_existed_before_seed"
