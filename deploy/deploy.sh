#!/usr/bin/env bash
#
# Routine deploy of Portal RYR. Runs ON THE SERVER over SSH:
#
#   cd /home/<user>/<app-path> && bash deploy/deploy.sh
#
# Safe to run repeatedly. Frontend assets arrive already built in
# public/build (there is no Node on the server). Contains no secrets:
# everything sensitive lives in the server's .env.
#
# Overrides (optional):
#   PHP=/opt/cpanel/ea-php83/root/usr/bin/php   PHP binary to use
#   COMPOSER_BIN="php ~/composer.phar"          Composer command
#
# COMPOSER_BIN is used instead of COMPOSER because Composer itself reads the
# COMPOSER variable as the name of the composer.json file.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

PHP="${PHP:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

log() {
    printf '\n==> %s\n' "$1"
}

bring_up() {
    log "Bringing the application back up"
    "$PHP" artisan up || true
}

if [ -f vendor/autoload.php ]; then
    log "Entering maintenance mode"
    "$PHP" artisan down --retry=60
fi

# From here on, whatever happens, the site must not stay in maintenance mode.
trap bring_up EXIT

log "Pulling latest code"
git pull --ff-only

log "Installing PHP dependencies"
# shellcheck disable=SC2086 # COMPOSER_BIN may intentionally hold "php composer.phar".
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction

log "Running database migrations"
"$PHP" artisan migrate --force

log "Caching config, routes, views and events"
"$PHP" artisan optimize

if [ ! -e public/storage ]; then
    log "Linking public/storage"
    "$PHP" artisan storage:link
fi

trap - EXIT
bring_up

log "Deploy finished"
