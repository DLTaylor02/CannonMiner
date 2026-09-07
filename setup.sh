#!/usr/bin/env bash
# CannonMiner deployment installer for Debian and Ubuntu. Re-running it performs
# an in-place update while preserving configuration, dependencies, and runtime data.
set -Eeuo pipefail

# Configuration
APP_NAME="CannonMiner"
APP_SLUG="cannonminer"
DEFAULT_PORT="3636"
MIN_PHP_VERSION="8.2.0"
DEFAULT_DB_NAME="cannonminer"
DEFAULT_DB_USER="cannonminer"
NGINX_TEMPLATE_RELATIVE="config/nginx.conf.example"
PHP_MEMORY_LIMIT="512M"

ROOT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
INSTALL_USER="${CANNONMINER_INSTALL_USER:-${SUDO_USER:-$(id -un)}}"
APP_DB_NAME="${CANNONMINER_DB_NAME:-$DEFAULT_DB_NAME}"
APP_DB_USER="${CANNONMINER_DB_USER:-$DEFAULT_DB_USER}"
APP_SYSTEM_USER="$APP_SLUG"
DEPLOY_DIR="${CANNONMINER_INSTALL_DIR:-/var/www/$APP_SLUG}"
LISTEN_PORT="${1:-${CANNONMINER_LISTEN_PORT:-}}"
NGINX_TMP=""
CRON_TMP=""
LEGACY_CRON_TMP=""
FILTERED_CRON_TMP=""
FPM_POOL_TMP=""
WORKER_SERVICE_TMP=""

fail() { printf 'Error: %s\n' "$*" >&2; exit 1; }
info() { printf '\n==> %s\n' "$*"; }
cleanup() {
  [ -z "$NGINX_TMP" ] || rm -f "$NGINX_TMP"
  [ -z "$CRON_TMP" ] || rm -f "$CRON_TMP"
  [ -z "$LEGACY_CRON_TMP" ] || rm -f "$LEGACY_CRON_TMP"
  [ -z "$FILTERED_CRON_TMP" ] || rm -f "$FILTERED_CRON_TMP"
  [ -z "$FPM_POOL_TMP" ] || rm -f "$FPM_POOL_TMP"
  [ -z "$WORKER_SERVICE_TMP" ] || rm -f "$WORKER_SERVICE_TMP"
}
as_user() {
  local target="$1"
  shift
  if [ "$(id -un)" = "$target" ]; then
    "$@"
  elif [ "$(id -u)" -eq 0 ]; then
    runuser -u "$target" -- "$@"
  else
    sudo -u "$target" -- "$@"
  fi
}
ensure_system_user() {
  if id -u "$APP_SYSTEM_USER" >/dev/null 2>&1; then
    local shell
    shell="$(getent passwd "$APP_SYSTEM_USER" | cut -d: -f7)"
    case "$shell" in */nologin|*/false) return ;; esac
    fail "User '$APP_SYSTEM_USER' already exists as a login account; a dedicated non-login system account is required."
  fi
  if getent group "$APP_SYSTEM_USER" >/dev/null 2>&1; then
    useradd --system --home-dir "$DEPLOY_DIR" --shell /usr/sbin/nologin --gid "$APP_SYSTEM_USER" "$APP_SYSTEM_USER"
  else
    useradd --system --home-dir "$DEPLOY_DIR" --shell /usr/sbin/nologin --user-group "$APP_SYSTEM_USER"
  fi
}

case "$ROOT_DIR" in *"'"*|*$'\n'*) fail "The installation path must not contain an apostrophe or newline." ;; esac
case "$DEPLOY_DIR" in /*) ;; *) fail "CANNONMINER_INSTALL_DIR must be an absolute path." ;; esac
case "$DEPLOY_DIR" in *"'"*|*$'\n'*) fail "The installation path must not contain an apostrophe or newline." ;; esac
DEPLOY_DIR="$(realpath -m "$DEPLOY_DIR")"
case "$DEPLOY_DIR" in /|/var|/var/www) fail "Refusing to use broad installation path $DEPLOY_DIR." ;; esac
[[ "$APP_DB_NAME" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || fail "CANNONMINER_DB_NAME must be a valid PostgreSQL identifier."
[[ "$APP_DB_USER" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || fail "CANNONMINER_DB_USER must be a valid PostgreSQL identifier."

[ -r /etc/os-release ] || fail "Cannot identify this OS. Install PHP 8.2+, PostgreSQL, Composer, Nginx, and cron manually."
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}" in
  debian|ubuntu) ;;
  *) fail "Automatic installation supports Debian and Ubuntu only (found ${ID:-unknown}). See README.md for manual requirements." ;;
esac

if [ "$(id -u)" -ne 0 ]; then
  command -v sudo >/dev/null 2>&1 || fail "sudo or a root shell is required to install packages and services."
  info "Validating sudo access"
  exec sudo env CANNONMINER_INSTALL_USER="$INSTALL_USER" CANNONMINER_INSTALL_DIR="$DEPLOY_DIR" CANNONMINER_LISTEN_PORT="$LISTEN_PORT" CANNONMINER_DB_NAME="$APP_DB_NAME" CANNONMINER_DB_USER="$APP_DB_USER" CANNONMINER_DEPLOYED="${CANNONMINER_DEPLOYED:-0}" bash "$ROOT_DIR/setup.sh"
fi
SUDO=""
trap cleanup EXIT
trap 'exit 130' HUP INT TERM

if [ "${CANNONMINER_DEPLOYED:-0}" != "1" ]; then
  PACKAGES=(php-cli php-fpm php-pgsql php-mbstring php-xml php-curl composer postgresql postgresql-contrib nginx cron curl unzip openssl rsync ca-certificates)
  MISSING_PACKAGES=()
  for PACKAGE in "${PACKAGES[@]}"; do
    dpkg-query -W -f='${Status}' "$PACKAGE" 2>/dev/null | grep -q 'install ok installed' || MISSING_PACKAGES+=("$PACKAGE")
  done
  if [ "${#MISSING_PACKAGES[@]}" -gt 0 ]; then
    info "Installing missing system packages: ${MISSING_PACKAGES[*]}"
    $SUDO apt-get update
    $SUDO env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${MISSING_PACKAGES[@]}"
  else
    info "All required system packages are already installed"
  fi

  ensure_system_user

  if [ "$ROOT_DIR" != "$DEPLOY_DIR" ]; then
    case "$DEPLOY_DIR/" in "$ROOT_DIR/"*) fail "Deployment directory must not be inside the source checkout." ;; esac
    if [ -d "$DEPLOY_DIR" ] && [ -n "$(find "$DEPLOY_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
      if [ ! -f "$DEPLOY_DIR/router.py" ] \
        && { [ ! -f "$DEPLOY_DIR/composer.json" ] || ! grep -q '"cannonminer/cannonminer"' "$DEPLOY_DIR/composer.json"; }; then
        fail "Refusing to overwrite non-CannonMiner directory $DEPLOY_DIR."
      fi
    fi
    info "Deploying application to $DEPLOY_DIR"
    $SUDO install -d -m 0755 "$DEPLOY_DIR"
    $SUDO rsync -a --delete \
      --exclude '.git/' --exclude '.env' --exclude 'vendor/' --exclude 'var/' \
      --exclude 'debian-php-postgres-nginx-setup.sh' \
      "$ROOT_DIR/" "$DEPLOY_DIR/"
    if [ -f "$ROOT_DIR/.env" ] && [ ! -f "$DEPLOY_DIR/.env" ]; then
      $SUDO install -m 0640 "$ROOT_DIR/.env" "$DEPLOY_DIR/.env"
    fi
    $SUDO chown -R "$INSTALL_USER":"$APP_SYSTEM_USER" "$DEPLOY_DIR"
    info "Continuing installation from $DEPLOY_DIR"
    exec env \
      CANNONMINER_INSTALL_USER="$INSTALL_USER" \
      CANNONMINER_DEPLOYED=1 \
      CANNONMINER_INSTALL_DIR="$DEPLOY_DIR" \
      CANNONMINER_LISTEN_PORT="$LISTEN_PORT" \
      CANNONMINER_DB_NAME="$APP_DB_NAME" \
      CANNONMINER_DB_USER="$APP_DB_USER" \
      bash "$DEPLOY_DIR/setup.sh"
  fi
fi

ensure_system_user

if [ -z "$LISTEN_PORT" ]; then
  EXISTING_PORT=""
  if [ -f /etc/nginx/sites-available/cannonminer ]; then
    EXISTING_PORT="$(sed -nE 's/^[[:space:]]*listen[[:space:]]+([0-9]+);.*/\1/p' /etc/nginx/sites-available/cannonminer | head -n 1)"
  fi
  PORT_DEFAULT="${EXISTING_PORT:-$DEFAULT_PORT}"
  if [ -t 0 ]; then
    printf 'Application port [%s]: ' "$PORT_DEFAULT"
    IFS= read -r LISTEN_PORT || fail "Unable to read the Nginx listen port."
    LISTEN_PORT="${LISTEN_PORT:-$PORT_DEFAULT}"
  else
    LISTEN_PORT="$PORT_DEFAULT"
    info "Using Nginx port $LISTEN_PORT (noninteractive installation)"
  fi
fi
case "$LISTEN_PORT" in ''|*[!0-9]*) fail "Nginx listen port must be a number from 1 to 65535." ;; esac
[ "$LISTEN_PORT" -ge 1 ] && [ "$LISTEN_PORT" -le 65535 ] || fail "Nginx listen port must be from 1 to 65535."

command -v php >/dev/null 2>&1 || fail "PHP installation did not provide a php executable."
command -v composer >/dev/null 2>&1 || fail "Composer installation failed."
command -v psql >/dev/null 2>&1 || fail "PostgreSQL client installation failed."
php -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' "$MIN_PHP_VERSION" || fail "PHP $MIN_PHP_VERSION+ is required; found $(php -r 'echo PHP_VERSION;')."
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' || fail "PHP extension pdo_pgsql is not enabled."
COMPOSER_VERSION="$(composer --version --no-ansi | awk '{print $3}')"
php -r 'exit(version_compare($argv[1], "2.0.0", ">=") ? 0 : 1);' "$COMPOSER_VERSION" || fail "Composer 2+ is required; found $COMPOSER_VERSION."

$SUDO systemctl enable --now postgresql nginx cron
POSTGRES_VERSION="$(as_user postgres psql -tAc 'SHOW server_version_num' | tr -d '[:space:]')"
[ "$POSTGRES_VERSION" -ge 140000 ] || fail "PostgreSQL 14+ is required; server_version_num is $POSTGRES_VERSION."
PHP_SHORT_VERSION="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
PHP_FPM_SERVICE="php${PHP_SHORT_VERSION}-fpm.service"
PHP_FPM_BIN="$(command -v "php-fpm${PHP_SHORT_VERSION}" || true)"
[ -n "$PHP_FPM_BIN" ] || fail "PHP-FPM $PHP_SHORT_VERSION was not installed alongside the PHP CLI."
systemctl list-unit-files "$PHP_FPM_SERVICE" --no-legend 2>/dev/null | grep -q "$PHP_FPM_SERVICE" || fail "PHP-FPM service $PHP_FPM_SERVICE was not found."
$SUDO systemctl enable --now "$PHP_FPM_SERVICE"
SESSION_DIR="/var/lib/cannonminer/sessions"
FPM_SOCKET="/run/php/cannonminer.sock"
$SUDO install -d -o "$APP_SYSTEM_USER" -g "$APP_SYSTEM_USER" -m 0700 "$SESSION_DIR"
$SUDO install -d -o "$APP_SYSTEM_USER" -g "$APP_SYSTEM_USER" -m 0750 /var/log/cannonminer
$SUDO touch /var/log/cannonminer/php-error.log
$SUDO chown "$APP_SYSTEM_USER":"$APP_SYSTEM_USER" /var/log/cannonminer/php-error.log
$SUDO chmod 0640 /var/log/cannonminer/php-error.log
$SUDO rm -f /etc/php/*/fpm/conf.d/99-cannonminer.ini /etc/php/*/cli/conf.d/99-cannonminer.ini
$SUDO rm -f /etc/php/*/fpm/pool.d/cannonminer.conf
FPM_POOL_TMP="$(mktemp)"
cat > "$FPM_POOL_TMP" <<FPM
[cannonminer]
user = $APP_SYSTEM_USER
group = $APP_SYSTEM_USER
listen = $FPM_SOCKET
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 15s
pm.max_requests = 250
clear_env = yes
security.limit_extensions = .php
php_admin_value[memory_limit] = $PHP_MEMORY_LIMIT
php_admin_value[max_execution_time] = 0
php_admin_value[max_input_time] = 0
php_admin_value[session.save_path] = $SESSION_DIR
php_admin_value[session.use_strict_mode] = 1
php_admin_value[session.cookie_httponly] = 1
php_admin_value[error_log] = /var/log/cannonminer/php-error.log
php_admin_flag[log_errors] = on
FPM
$SUDO install -m 0644 "$FPM_POOL_TMP" "/etc/php/$PHP_SHORT_VERSION/fpm/pool.d/cannonminer.conf"
rm -f "$FPM_POOL_TMP"

cd "$ROOT_DIR"
if [ ! -f .env ]; then
  info "Creating or reusing PostgreSQL database '$APP_DB_NAME'"
  APP_DB_PASSWORD="$(openssl rand -hex 24)"
  if ! as_user postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$APP_DB_USER'" | grep -q 1; then
    as_user postgres psql -v ON_ERROR_STOP=1 -c "CREATE ROLE $APP_DB_USER LOGIN PASSWORD '$APP_DB_PASSWORD'"
  else
    as_user postgres psql -v ON_ERROR_STOP=1 -c "ALTER ROLE $APP_DB_USER PASSWORD '$APP_DB_PASSWORD'"
  fi
  if ! as_user postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$APP_DB_NAME'" | grep -q 1; then
    as_user postgres createdb -O "$APP_DB_USER" "$APP_DB_NAME"
  fi
  as_user postgres psql -v ON_ERROR_STOP=1 -d "$APP_DB_NAME" <<SQL
GRANT CONNECT ON DATABASE $APP_DB_NAME TO $APP_DB_USER;
GRANT USAGE, CREATE ON SCHEMA public TO $APP_DB_USER;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO $APP_DB_USER;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO $APP_DB_USER;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO $APP_DB_USER;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO $APP_DB_USER;
SQL
  umask 077
  {
    echo "DATABASE_URL=pgsql:host=127.0.0.1;port=5432;dbname=$APP_DB_NAME"
    echo "DATABASE_USER=$APP_DB_USER"
    echo "DATABASE_PASSWORD=$APP_DB_PASSWORD"
    echo "TRUSTED_PROXIES="
    echo "APP_ENV=production"
  } > .env
else
  info "Preserving existing .env database connection"
fi
$SUDO chown "$INSTALL_USER":"$APP_SYSTEM_USER" .env
$SUDO chmod 0640 .env

info "Installing PHP dependencies and database schema"
if [ "$(id -un)" = "$INSTALL_USER" ]; then
  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
else
  as_user "$INSTALL_USER" composer install --no-dev --optimize-autoloader --no-interaction
fi
PHP_CLI=(php -d memory_limit="$PHP_MEMORY_LIMIT" -d max_execution_time=0 -d max_input_time=0)
while IFS= read -r -d '' PHP_FILE; do
  "${PHP_CLI[@]}" -l "$PHP_FILE" >/dev/null
done < <(find public src bin -type f -name '*.php' -print0)
"${PHP_CLI[@]}" bin/install.php
$SUDO chown -R "$INSTALL_USER":"$APP_SYSTEM_USER" "$ROOT_DIR"
$SUDO find "$ROOT_DIR" -type d -exec chmod 0750 {} +
$SUDO find "$ROOT_DIR" -type f -exec chmod 0640 {} +
$SUDO chmod 0751 "$ROOT_DIR"
$SUDO chmod 0750 "$ROOT_DIR/setup.sh"
$SUDO find "$ROOT_DIR/public" -type d -exec chmod 0755 {} +
$SUDO find "$ROOT_DIR/public" -type f -exec chmod 0644 {} +
$SUDO install -d -o "$APP_SYSTEM_USER" -g "$APP_SYSTEM_USER" -m 0750 "$ROOT_DIR/var"
$SUDO "$PHP_FPM_BIN" -t
$SUDO systemctl reload "$PHP_FPM_SERVICE"

PHP_FPM_SOCKET="$FPM_SOCKET"
for _ in $(seq 1 20); do
  [ -S "$PHP_FPM_SOCKET" ] && break
  sleep 0.25
done
[ -S "$PHP_FPM_SOCKET" ] || fail "CannonMiner PHP-FPM socket was not created at $PHP_FPM_SOCKET."
as_user www-data test -r "$ROOT_DIR/public/index.php" || fail "Nginx cannot read $ROOT_DIR/public. Correct the directory permissions, then rerun setup."
as_user "$APP_SYSTEM_USER" test -r "$ROOT_DIR/.env" || fail "The CannonMiner PHP-FPM user cannot read .env."

info "Configuring Nginx"
for ENABLED_SITE in /etc/nginx/sites-enabled/*; do
  [ -e "$ENABLED_SITE" ] || continue
  [ "$(realpath -m "$ENABLED_SITE")" = "$(realpath -m /etc/nginx/sites-available/cannonminer)" ] && continue
  if grep -Eq "^[[:space:]]*listen[^;]*([[:space:]:])${LISTEN_PORT}([[:space:]]|;)" "$ENABLED_SITE"; then
    fail "Port $LISTEN_PORT is already configured by Nginx site $ENABLED_SITE. Rerun setup and choose a different port."
  fi
done
PORT_IN_USE=0
if command -v ss >/dev/null 2>&1 && ss -H -ltn | awk -v port=":$LISTEN_PORT" '$4 ~ (port "$") {found=1} END {exit !found}'; then
  PORT_IN_USE=1
fi
OWN_SITE_USES_PORT=0
if [ -f /etc/nginx/sites-available/cannonminer ] \
  && grep -Eq "^[[:space:]]*listen[^;]*([[:space:]:])${LISTEN_PORT}([[:space:]]|;)" /etc/nginx/sites-available/cannonminer; then
  OWN_SITE_USES_PORT=1
fi
if [ "$PORT_IN_USE" -eq 1 ] && [ "$OWN_SITE_USES_PORT" -eq 0 ]; then
  fail "Port $LISTEN_PORT is already in use by another service. Rerun setup and choose a different port."
fi
NGINX_TMP="$(mktemp)"
CRON_TMP="$(mktemp)"
NGINX_TEMPLATE="$ROOT_DIR/$NGINX_TEMPLATE_RELATIVE"
[ -f "$NGINX_TEMPLATE" ] || fail "Missing Nginx template: $NGINX_TEMPLATE"
NGINX_PROJECT_ROOT="$(printf '%s' "$ROOT_DIR" | sed 's/[&|\\]/\\&/g')"
NGINX_FPM_SOCKET="$(printf '%s' "$PHP_FPM_SOCKET" | sed 's/[&|\\]/\\&/g')"
sed -e "s|{{PORT}}|$LISTEN_PORT|g" \
    -e "s|{{PROJECT_ROOT}}|$NGINX_PROJECT_ROOT|g" \
    -e "s|{{FPM_SOCKET}}|$NGINX_FPM_SOCKET|g" \
    "$NGINX_TEMPLATE" > "$NGINX_TMP"
$SUDO install -m 0644 "$NGINX_TMP" /etc/nginx/sites-available/cannonminer
$SUDO ln -sfn /etc/nginx/sites-available/cannonminer /etc/nginx/sites-enabled/cannonminer
$SUDO nginx -t || fail "Nginx rejected the generated configuration."
$SUDO systemctl reload nginx

info "Installing analysis worker"
WORKER_SERVICE_TMP="$(mktemp)"
cat > "$WORKER_SERVICE_TMP" <<SERVICE
[Unit]
Description=CannonMiner analysis worker
After=network.target postgresql.service
Requires=postgresql.service

[Service]
Type=simple
User=$APP_SYSTEM_USER
Group=$APP_SYSTEM_USER
WorkingDirectory=$ROOT_DIR
ExecStart=$(command -v php) -d memory_limit=$PHP_MEMORY_LIMIT -d max_execution_time=0 -d max_input_time=0 "$ROOT_DIR/bin/analyze-worker.php"
Restart=always
RestartSec=3
UMask=0027
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=read-only
ProtectSystem=strict

[Install]
WantedBy=multi-user.target
SERVICE
$SUDO install -m 0644 "$WORKER_SERVICE_TMP" /etc/systemd/system/cannonminer-worker.service
rm -f "$WORKER_SERVICE_TMP"
$SUDO systemd-analyze verify /etc/systemd/system/cannonminer-worker.service
$SUDO systemctl daemon-reload
$SUDO systemctl enable cannonminer-worker.service
$SUDO systemctl restart cannonminer-worker.service

info "Installing scheduled collector"
mkdir -p "$ROOT_DIR/var"
touch "$ROOT_DIR/var/collector.log"
as_user "$INSTALL_USER" composer licenses --format=json --no-dev > "$ROOT_DIR/var/composer-licenses.json"
$SUDO chown -R "$APP_SYSTEM_USER":"$APP_SYSTEM_USER" "$ROOT_DIR/var"
$SUDO chmod 0750 "$ROOT_DIR/var"

info "Cleaning up legacy collector schedule"
LEGACY_CRON_TMP="$(mktemp)"
FILTERED_CRON_TMP="$(mktemp)"
if as_user "$INSTALL_USER" crontab -l > "$LEGACY_CRON_TMP" 2>/dev/null; then
  grep -Eiv 'cannonminer.*run_main\.sh|run_main\.sh.*cannonminer' "$LEGACY_CRON_TMP" > "$FILTERED_CRON_TMP" || true
  if ! cmp -s "$LEGACY_CRON_TMP" "$FILTERED_CRON_TMP"; then
    as_user "$INSTALL_USER" crontab - < "$FILTERED_CRON_TMP"
    info "Removed legacy Python collector entries from $INSTALL_USER's crontab"
  fi
fi
printf '%s\n' "* * * * * $APP_SYSTEM_USER cd '$ROOT_DIR' && $(command -v php) -d memory_limit=$PHP_MEMORY_LIMIT -d max_execution_time=0 -d max_input_time=0 bin/collect.php --scheduled >> '$ROOT_DIR/var/collector.log' 2>&1" > "$CRON_TMP"
$SUDO install -m 0644 "$CRON_TMP" /etc/cron.d/cannonminer

info "Running final checks"
as_user "$INSTALL_USER" composer check-platform-reqs --no-dev
$SUDO systemctl is-active --quiet postgresql || fail "PostgreSQL is not running."
$SUDO systemctl is-active --quiet "$PHP_FPM_SERVICE" || fail "PHP-FPM is not running."
$SUDO systemctl is-active --quiet cannonminer-worker.service || fail "CannonMiner analysis worker is not running."
$SUDO systemctl is-active --quiet nginx || fail "Nginx is not running."
$SUDO systemctl is-active --quiet cron || fail "cron is not running."

APP_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
printf '\n%s is deployed and ready.\n' "$APP_NAME"
printf 'Application: http://%s:%s/\n' "${APP_IP:-localhost}" "$LISTEN_PORT"
printf 'Deployment: %s\n' "$ROOT_DIR"
printf 'Configuration: %s/.env\n' "$ROOT_DIR"
printf 'Review the collection interval, Google API key, and collection authorization in WebUI Settings.\n'
