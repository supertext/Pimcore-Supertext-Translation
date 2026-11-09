#!/bin/bash
# Demo container start: database, Pimcore install (first start only), classes, bundle,
# search index, demo setup, then Mercure, the Messenger worker and Apache.
# Variables: demo/.env.example. Passwords and keys are never printed.
set -euo pipefail
cd /app/demo/project
echo "[demo] Starting…"
DEMO_ADMIN_EMAIL="${DEMO_ADMIN_EMAIL:-}" DEMO_ADMIN_PASSWORD="${DEMO_ADMIN_PASSWORD:-}"
DEMO_EDITOR_EMAIL="${DEMO_EDITOR_EMAIL:-}" DEMO_EDITOR_PASSWORD="${DEMO_EDITOR_PASSWORD:-}"

need() {
  if [ -z "${!1:-}" ]; then echo "[demo] $1 is not set. See demo/.env.example."; exit 1; fi
}

# MYSQL_URL (Railway's MySQL service) or DATABASE_URL; the demo uses its own database
# PIMCORE_DB_NAME on that server and creates it if it is missing.
DB_SOURCE="${DATABASE_URL:-${MYSQL_URL:-}}"
if [ -z "$DB_SOURCE" ]; then echo "[demo] Set DATABASE_URL or MYSQL_URL."; exit 1; fi
eval "$(DB_SOURCE="$DB_SOURCE" php -r '
  $u = parse_url(getenv("DB_SOURCE"));
  $name = getenv("PIMCORE_DB_NAME") ?: "pimcore";
  if (!preg_match("/^[A-Za-z0-9_]+$/", $name)) { fwrite(STDERR, "PIMCORE_DB_NAME may only contain letters, digits and _\n"); exit(1); }
  $url = sprintf("mysql://%s:%s@%s:%d/%s?serverVersion=%s", rawurlencode(urldecode($u["user"] ?? "")), rawurlencode(urldecode($u["pass"] ?? "")),
    $u["host"] ?? "127.0.0.1", $u["port"] ?? 3306, $name, getenv("PIMCORE_DB_SERVER_VERSION") ?: "8.0.0");
  echo "export DATABASE_URL=" . escapeshellarg($url) . "\n";
')"
for attempt in $(seq 1 30); do
  if php -r '
    $u = parse_url(getenv("DATABASE_URL"));
    $name = ltrim($u["path"], "/");
    $pdo = new PDO(sprintf("mysql:host=%s;port=%d", $u["host"], $u["port"] ?? 3306), urldecode($u["user"]), urldecode($u["pass"] ?? ""));
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  ' 2>/tmp/db.log; then break; fi
  if [ "$attempt" = 30 ]; then echo "[demo] Database not reachable:"; cat /tmp/db.log; exit 1; fi
  echo "[demo] Database not ready yet, retrying…"; sleep 3
done

need APPLICATION_SECRET
need PIMCORE_ENCRYPTION_SECRET
need PIMCORE_INSTANCE_IDENTIFIER
need PIMCORE_PRODUCT_KEY
need MERCURE_JWT_KEY
need PIMCORE_OPENSEARCH_DSN
export PIMCORE_MESSENGER_TRANSPORT_DSN_PREFIX="${PIMCORE_MESSENGER_TRANSPORT_DSN_PREFIX:-doctrine://default?queue_name=}"
PUBLIC_URL="${PUBLIC_URL:-${RAILWAY_PUBLIC_DOMAIN:+https://${RAILWAY_PUBLIC_DOMAIN}}}"
PUBLIC_URL="${PUBLIC_URL:-http://localhost:${PORT:-8080}}"
export MERCURE_URL="${MERCURE_URL:-${PUBLIC_URL}/.well-known/mercure}"
export MERCURE_SERVER_URL="${MERCURE_SERVER_URL:-http://127.0.0.1:3000/.well-known/mercure}"
export TRUSTED_PROXIES="${TRUSTED_PROXIES:-127.0.0.1,REMOTE_ADDR}"

console() { runuser -u www-data -- env DEMO_ADMIN_EMAIL="${DEMO_ADMIN_EMAIL:-}" DEMO_ADMIN_PASSWORD="${DEMO_ADMIN_PASSWORD:-}" \
  DEMO_EDITOR_EMAIL="${DEMO_EDITOR_EMAIL:-}" DEMO_EDITOR_PASSWORD="${DEMO_EDITOR_PASSWORD:-}" bin/console "$@"; }

installed=$(php -r '
  $u = parse_url(getenv("DATABASE_URL"));
  $pdo = new PDO(sprintf("mysql:host=%s;port=%d;dbname=%s", $u["host"], $u["port"] ?? 3306, ltrim($u["path"], "/")), urldecode($u["user"]), urldecode($u["pass"] ?? ""));
  echo $pdo->query("SHOW TABLES LIKE \"users\"")->fetchColumn() ? "yes" : "no";
')
if [ "$installed" = "no" ]; then
  echo "[demo] First start: installing Pimcore (takes a few minutes)…"
  # The installer needs an administrator. DEMO_ADMIN_* when set, otherwise a random password
  # nobody knows (the demo's own accounts are created below).
  admin_user="pimcore-install"
  admin_password="$(php -r 'echo bin2hex(random_bytes(24));')"
  if [ "${#DEMO_ADMIN_EMAIL}" -ge 4 ] && [ "${#DEMO_ADMIN_PASSWORD}" -ge 4 ]; then
    admin_user="$DEMO_ADMIN_EMAIL"; admin_password="$DEMO_ADMIN_PASSWORD"
  fi
  if ! runuser -u www-data -- env PIMCORE_ADMIN_USER="$admin_user" PIMCORE_ADMIN_PASSWORD="$admin_password" \
      vendor/bin/pimcore-install --install-profile='App\Installer\SkeletonProfile' --no-interaction > /tmp/install.log 2>&1; then
    echo "[demo] Pimcore install failed:"; grep -viE 'password|secret|key' /tmp/install.log | tail -40; exit 1
  fi
  unset admin_password
  rm -f .env.local
  echo "[demo] Pimcore installed."
fi

# Console commands run with `docker exec` (or Railway's shell) don't inherit the DATABASE_URL derived
# above: keep it in .env.local, which Symfony reads (real environment variables still win).
umask 027
printf "DATABASE_URL='%s'\n" "$DATABASE_URL" > .env.local
chown root:www-data .env.local
umask 022

console cache:clear --no-warmup > /dev/null
# The warmup also unpacks Pimcore Studio's frontend (into vendor/pimcore/*/public) before
# assets:install copies it to public/bundles.
console cache:warmup > /dev/null
console doctrine:migrations:migrate --no-interaction --allow-no-migration --prefix='Pimcore\Bundle\CoreBundle' > /dev/null 2>&1 || true
console pimcore:bundle:install SupertextTranslationBundle > /dev/null 2>&1 || true
console pimcore:deployment:classes-rebuild --create-classes --no-interaction | grep -v '^$' || true
console assets:install public > /dev/null
# OpenSearch keeps no data between deploys on Railway: rebuild the search index every start.
console generic-data-index:update:index --no-interaction > /tmp/index.log 2>&1 || { echo "[demo] Search index update failed:"; tail -20 /tmp/index.log; }
console supertext:demo-setup

# Mercure hub on 127.0.0.1:3000 (Apache proxies /.well-known/mercure to it).
mkdir -p /tmp/mercure/caddy
MERCURE_PUBLISHER_JWT_KEY="$MERCURE_JWT_KEY" MERCURE_SUBSCRIBER_JWT_KEY="$MERCURE_JWT_KEY" SERVER_NAME=':3000' \
  MERCURE_EXTRA_DIRECTIVES='anonymous' GLOBAL_OPTIONS='auto_https off' XDG_DATA_HOME=/tmp/mercure XDG_CONFIG_HOME=/tmp/mercure \
  mercure run --config /etc/mercure/Caddyfile --adapter caddyfile > /tmp/mercure.log 2>&1 &

# Messenger worker (search index updates, maintenance), restarted every hour.
(
  while true; do
    console messenger:consume pimcore_core pimcore_maintenance pimcore_scheduled_tasks pimcore_image_optimize \
      pimcore_asset_update pimcore_generic_data_index_queue pimcore_generic_execution_engine \
      --time-limit=3600 --memory-limit=256M >> /tmp/worker.log 2>&1 || sleep 5
  done
) &
# Pimcore maintenance every 15 minutes.
( while true; do sleep 900; console pimcore:maintenance >> /tmp/maintenance.log 2>&1 || true; done ) &

# Exactly one Apache MPM (prefork, for mod_php); Railway's runtime otherwise reports more than one.
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
sed -ri "s/Listen [0-9]+/Listen ${PORT:-8080}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT:-8080}>/" /etc/apache2/sites-available/000-default.conf
# Apache's PHP sees the same settings as the console.
for v in DATABASE_URL APPLICATION_SECRET PIMCORE_ENCRYPTION_SECRET PIMCORE_INSTANCE_IDENTIFIER PIMCORE_PRODUCT_KEY \
  PIMCORE_OPENSEARCH_DSN PIMCORE_MESSENGER_TRANSPORT_DSN_PREFIX MERCURE_JWT_KEY MERCURE_URL MERCURE_SERVER_URL TRUSTED_PROXIES \
  SUPERTEXT_API_KEY SUPERTEXT_API_URL SUPERTEXT_VERSION_REPORTS SUPERTEXT_REPORT_URL APP_ENV APP_DEBUG; do
  if [ -n "${!v:-}" ]; then printf 'PassEnv %s\n' "$v"; fi
done > /etc/apache2/conf-enabled/pimcore-env.conf
echo "[demo] Starting Apache on port ${PORT:-8080} (${PUBLIC_URL})."
exec apache2-foreground
