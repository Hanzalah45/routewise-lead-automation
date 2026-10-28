#!/bin/sh
set -e
cd "$(dirname "$0")/.."

PORT="${PORT:-10000}"

if [ -z "$APP_KEY" ]; then
    export APP_KEY="$(php artisan key:generate --show --no-ansi)"
fi
if [ -z "$APP_URL" ] && [ -n "$RENDER_EXTERNAL_URL" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi

# Fresh demo data on every start.
php artisan routewise:demo-reset --no-interaction

# CRM retries every minute, and the daily reset at 03:00, both need the scheduler.
php artisan schedule:work --no-interaction >/dev/null 2>&1 &

exec php artisan serve --host=0.0.0.0 --port="$PORT" --no-reload
