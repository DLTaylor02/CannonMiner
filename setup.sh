#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
INSTALL_USER="${SUDO_USER:-$(id -un)}"
APP_DB_NAME="cannonminer"
APP_DB_USER="cannonminer"
DEPLOY_DIR="${CANNONMINER_INSTALL_DIR:-/var/www/cannonminer}"
PHP_LIMITS_TMP=""
NGINX_TMP=""
CRON_TMP=""

fail() { echo "ERROR: $*" >&2; exit 1; }
info() { echo "==> $*"; }
cleanup() {
  [ -z "$PHP_LIMITS_TMP" ] || rm -f "$PHP_LIMITS_TMP"
  [ -z "$NGINX_TMP" ] || rm -f "$NGINX_TMP"
  [ -z "$CRON_TMP" ] || rm -f "$CRON_TMP"
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

case "$ROOT_DIR" in *"'"*|*$'\n'*) fail "The installation path must not contain an apostrophe or newline." ;; esac
case "$DEPLOY_DIR" in /*) ;; *) fail "CANNONMINER_INSTALL_DIR must be an absolute path." ;; esac
case "$DEPLOY_DIR" in /|/var|/var/www) fail "Refusing to use broad installation path $DEPLOY_DIR." ;; esac
case "$DEPLOY_DIR" in *"'"*|*$'\n'*) fail "The installation path must not contain an apostrophe or newline." ;; esac

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
  exec sudo env CANNONMINER_INSTALL_DIR="$DEPLOY_DIR" CANNONMINER_DEPLOYED="${CANNONMINER_DEPLOYED:-0}" bash "$ROOT_DIR/setup.sh"
fi
SUDO=""
trap cleanup EXIT
trap 'exit 130' HUP INT TERM

if [ "${CANNONMINER_DEPLOYED:-0}" != "1" ]; then
  info "Installing PHP, PostgreSQL, Composer, Nginx, and cron"
  $SUDO apt-get update
  $SUDO env DEBIAN_FRONTEND=noninteractive apt-get install -y \
    php-cli php-fpm php-pgsql php-mbstring php-xml php-curl \
    composer postgresql postgresql-contrib nginx cron curl unzip openssl rsync

  DEPLOY_DIR="$(realpath -m "$DEPLOY_DIR")"
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
      "$ROOT_DIR/" "$DEPLOY_DIR/"
    if [ -f "$ROOT_DIR/.env" ] && [ ! -f "$DEPLOY_DIR/.env" ]; then
      $SUDO install -m 0640 "$ROOT_DIR/.env" "$DEPLOY_DIR/.env"
    fi
    $SUDO chown -R "$INSTALL_USER":www-data "$DEPLOY_DIR"
    info "Continuing installation from $DEPLOY_DIR"
    exec env \
      CANNONMINER_DEPLOYED=1 \
      CANNONMINER_INSTALL_DIR="$DEPLOY_DIR" \
      bash "$DEPLOY_DIR/setup.sh"
  fi
fi

command -v php >/dev/null 2>&1 || fail "PHP installation did not provide a php executable."
command -v composer >/dev/null 2>&1 || fail "Composer installation failed."
command -v psql >/dev/null 2>&1 || fail "PostgreSQL client installation failed."
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || fail "PHP 8.2+ is required; found $(php -r 'echo PHP_VERSION;')."
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' || fail "PHP extension pdo_pgsql is not enabled."
COMPOSER_VERSION="$(composer --version --no-ansi | awk '{print $3}')"
php -r 'exit(version_compare($argv[1], "2.0.0", ">=") ? 0 : 1);' "$COMPOSER_VERSION" || fail "Composer 2+ is required; found $COMPOSER_VERSION."

$SUDO systemctl enable --now postgresql nginx cron
POSTGRES_VERSION="$(as_user postgres psql -tAc 'SHOW server_version_num' | tr -d '[:space:]')"
[ "$POSTGRES_VERSION" -ge 140000 ] || fail "PostgreSQL 14+ is required; server_version_num is $POSTGRES_VERSION."
PHP_FPM_SERVICE="$(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk 'NR==1 {print $1}')"
[ -n "$PHP_FPM_SERVICE" ] || fail "No PHP-FPM systemd service was found."
$SUDO systemctl enable --now "$PHP_FPM_SERVICE"
PHP_SHORT_VERSION="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
PHP_LIMITS_TMP="$(mktemp)"
printf '%s\n' 'memory_limit=512M' 'max_execution_time=0' 'max_input_time=0' > "$PHP_LIMITS_TMP"
$SUDO install -m 0644 "$PHP_LIMITS_TMP" "/etc/php/$PHP_SHORT_VERSION/fpm/conf.d/99-cannonminer.ini"
$SUDO install -m 0644 "$PHP_LIMITS_TMP" "/etc/php/$PHP_SHORT_VERSION/cli/conf.d/99-cannonminer.ini"
rm -f "$PHP_LIMITS_TMP"

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
    echo "APP_ENV=production"
  } > .env
else
  info "Preserving existing .env database connection"
fi
$SUDO chown "$INSTALL_USER":www-data .env
$SUDO chmod 0640 .env

info "Installing PHP dependencies and database schema"
if [ "$(id -un)" = "$INSTALL_USER" ]; then
  composer install --no-dev --optimize-autoloader --no-interaction
else
  as_user "$INSTALL_USER" composer install --no-dev --optimize-autoloader --no-interaction
fi
php bin/install.php
$SUDO systemctl reload "$PHP_FPM_SERVICE"

PHP_FPM_SOCKET="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' -print -quit)"
[ -n "$PHP_FPM_SOCKET" ] || fail "PHP-FPM socket was not found under /run/php."
as_user www-data test -r "$ROOT_DIR/public/index.php" || fail "Nginx cannot read $ROOT_DIR/public. Correct the directory permissions, then rerun setup."
as_user www-data test -r "$ROOT_DIR/.env" || fail "PHP-FPM cannot read .env; verify its installer:www-data ownership and parent-directory access."

info "Configuring Nginx"
NGINX_TMP="$(mktemp)"
CRON_TMP="$(mktemp)"
cat > "$NGINX_TMP" <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root "$ROOT_DIR/public";
    index index.php;
    client_max_body_size 2m;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCKET;
        fastcgi_read_timeout 3600s;
    }
    location ~ /\. { deny all; }
}
NGINX
$SUDO install -m 0644 "$NGINX_TMP" /etc/nginx/sites-available/cannonminer
$SUDO ln -sfn /etc/nginx/sites-available/cannonminer /etc/nginx/sites-enabled/cannonminer
$SUDO rm -f /etc/nginx/sites-enabled/default
$SUDO nginx -t || fail "Nginx rejected the generated configuration."
$SUDO systemctl reload nginx

info "Installing scheduled collector"
mkdir -p "$ROOT_DIR/var"
touch "$ROOT_DIR/var/collector.log"
$SUDO chown -R "$INSTALL_USER":"$(id -gn "$INSTALL_USER")" "$ROOT_DIR/var"
as_user "$INSTALL_USER" composer licenses --format=json --no-dev > "$ROOT_DIR/var/composer-licenses.json"
printf '%s\n' "* * * * * $INSTALL_USER cd '$ROOT_DIR' && $(command -v php) bin/collect.php --scheduled >> '$ROOT_DIR/var/collector.log' 2>&1" > "$CRON_TMP"
$SUDO install -m 0644 "$CRON_TMP" /etc/cron.d/cannonminer
$SUDO systemctl restart cron

info "Running final checks"
as_user "$INSTALL_USER" composer check-platform-reqs --no-dev
$SUDO systemctl is-active --quiet postgresql || fail "PostgreSQL is not running."
$SUDO systemctl is-active --quiet "$PHP_FPM_SERVICE" || fail "PHP-FPM is not running."
$SUDO systemctl is-active --quiet nginx || fail "Nginx is not running."
$SUDO systemctl is-active --quiet cron || fail "cron is not running."

APP_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo
echo "CannonMiner is installed at http://${APP_IP:-localhost}/"
echo "Review the collection interval, Google API key, and collection authorization in WebUI Settings."
