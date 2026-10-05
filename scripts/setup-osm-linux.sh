#!/usr/bin/env bash
set -Eeuo pipefail

project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_dir"

fail() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

install_osmium() {
    if [[ "$(id -u)" -eq 0 ]]; then
        sudo_cmd=()
    elif command -v sudo >/dev/null 2>&1; then
        sudo_cmd=(sudo)
    else
        fail "Osmium is missing; install osmium-tool with your system package manager or configure sudo."
    fi

    if command -v apt-get >/dev/null 2>&1; then
        "${sudo_cmd[@]}" apt-get update
        "${sudo_cmd[@]}" apt-get install -y osmium-tool
    elif command -v dnf >/dev/null 2>&1; then
        "${sudo_cmd[@]}" dnf install -y osmium-tool
    elif command -v zypper >/dev/null 2>&1; then
        "${sudo_cmd[@]}" zypper --non-interactive install osmium-tool
    else
        fail "Unsupported package manager. Install osmium-tool manually, then run this script again."
    fi
}

command -v php >/dev/null 2>&1 || fail "PHP CLI is required."
[[ -f .env ]] || fail "Create and configure .env first, including the database connection."

if [[ ! -f vendor/autoload.php ]]; then
    command -v composer >/dev/null 2>&1 || fail "Composer is required because vendor/autoload.php is missing."
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
fi

osmium_binary="osmium"
if grep -q '^OSMIUM_BINARY=' .env; then
    osmium_binary="$(sed -n 's/^OSMIUM_BINARY=//p' .env | tail -n 1 | tr -d "\"'[:space:]")"
fi
if [[ -z "$osmium_binary" ]]; then
    osmium_binary="osmium"
    if grep -q '^OSMIUM_BINARY=' .env; then
        sed -i 's/^OSMIUM_BINARY=.*/OSMIUM_BINARY=osmium/' .env
    else
        printf '\nOSMIUM_BINARY=osmium\n' >> .env
    fi
fi

if ! command -v "$osmium_binary" >/dev/null 2>&1; then
    [[ "$osmium_binary" == "osmium" ]] || fail "Configured Osmium executable '$osmium_binary' was not found. Set OSMIUM_BINARY=osmium to install the system package."
    install_osmium
fi
command -v "$osmium_binary" >/dev/null 2>&1 || fail "Could not find Osmium executable '$osmium_binary' after installation."

if ! grep -q '^OSMIUM_BINARY=' .env; then
    printf '\nOSMIUM_BINARY=osmium\n' >> .env
fi

app_key="$(sed -n 's/^APP_KEY=//p' .env | tail -n 1 | tr -d "\"'[:space:]")"
if [[ -z "$app_key" ]]; then
    php artisan key:generate --force
fi

php artisan migrate --force
php artisan osm:sync-romania

printf 'Setup complete. Keeping the Laravel scheduler running; stop with Ctrl+C.\n'
exec php artisan schedule:work
