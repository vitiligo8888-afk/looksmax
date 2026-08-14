#!/bin/sh
set -e
APP=/flarum/app

# First boot: lay down the app skeleton. Pinned to the 1.8 line — 2.0 is still
# at rc and its extender APIs moved, which would strand our extensions.
#
# The constraint is on the PACKAGE, not on --stability: `--stability=stable`
# pins the release CHANNEL only, so two deploys a week apart resolved to
# whatever flarum/flarum was newest that morning — including, once 2.0 leaves
# rc, across a major. The comment above stated the intent; this enforces it.
if [ ! -f "$APP/composer.json" ]; then
  echo "[entrypoint] creating flarum project (this takes a minute)…"
  composer create-project "flarum/flarum:^1.8" "$APP" --no-interaction
fi

cd "$APP"

# Restored-from-backup path: composer.json and composer.lock are present but
# vendor/ is not, because a backup of the app volume deliberately excludes
# vendor as regenerable. Without this the container starts and every request is
# a fatal "failed to open stream: vendor/autoload.php" — nginx answers 500 with
# nothing in the Flarum log, because Flarum never boots far enough to have one.
# --no-dev matches a production install; the lock file makes it reproducible.
if [ ! -f "$APP/vendor/autoload.php" ]; then
  echo "[entrypoint] vendor/ is missing — running composer install from the lock file…"
  composer install --no-interaction --no-dev --optimize-autoloader \
    || composer update --no-interaction --no-dev --optimize-autoloader
fi

# Local path repo so our own extensions are `composer require`-able by name and
# hot-editable on a bind mount — no packagist round-trip while developing.
if ! grep -q '"local-extensions"' composer.json 2>/dev/null; then
  echo "[entrypoint] registering local extension path repository"
  composer config repositories.local-extensions '{"type":"path","url":"/flarum/extensions/*","options":{"symlink":true}}' --no-interaction
fi

# Non-interactive install once the DB answers.
if [ ! -f "$APP/config.php" ]; then
  echo "[entrypoint] waiting for database…"
  until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASS"));' 2>/dev/null; do
    sleep 2
  done
  echo "[entrypoint] installing flarum"
  cat > /tmp/install.yml <<YAML
debug: ${FLARUM_DEBUG:-false}
baseUrl: ${FLARUM_BASE_URL:-http://localhost:8888}
databaseConfiguration:
  driver: mysql
  host: ${DB_HOST}
  database: ${DB_NAME}
  username: ${DB_USER}
  password: ${DB_PASS}
  prefix: ''
adminUser:
  username: ${ADMIN_USER:-admin}
  password: ${ADMIN_PASS}
  password_confirmation: ${ADMIN_PASS}
  email: ${ADMIN_EMAIL:-admin@looksmax.lat}
settings:
  forum_title: ${FORUM_TITLE:-Looksmax.lat}
YAML
  php flarum install --file=/tmp/install.yml
  rm -f /tmp/install.yml
fi

# storage/sessions is NOT created by `flarum install`, and Laravel's file
# session driver does not create it either — InstalledSite.php points the driver
# at storage/sessions and then fails to write, silently.
#
# The failure is vicious because nothing errors: every request gets a BRAND NEW
# session, so the CSRF token regenerates on every response. Reads keep working
# (and remember-me keeps users looking logged in), while every write in the
# forum — posting, marking read, buying from the store, editing a profile —
# fails 400 csrf_token_mismatch. That was live on this box on 2026-08-14 and was
# invisible in the logs: a mismatch is a 400, not an exception.
#
# Created here rather than by hand because $APP/storage is a docker volume:
# recreating it silently reintroduces the bug on the next deploy.
mkdir -p "$APP/storage/sessions"
chown -R www-data:www-data "$APP/storage" "$APP/public/assets" 2>/dev/null || true
php flarum cache:clear || true

exec "$@"
