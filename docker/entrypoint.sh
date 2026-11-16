#!/bin/sh
# Container entrypoint for every role (web, Horizon, scheduler, Vite).
#
# 1. Development only: install Composer dependencies and create .env when missing.
# 2. Wait until the database accepts connections.
# 3. Run migrations when RUN_MIGRATIONS=true (only the web container sets it).
# 4. Production only: cache config, routes, views and events.
# 5. Hand over to the container command.
set -eu

cd /app

if [ "${APP_ENV:-local}" != "production" ]; then
    if [ ! -f vendor/autoload.php ]; then
        echo "entrypoint: installing Composer dependencies"
        composer install --no-interaction --prefer-dist
    fi

    if [ ! -f .env ]; then
        echo "entrypoint: creating .env from .env.example"
        cp .env.example .env
    fi

    if grep -q '^APP_KEY=$' .env; then
        php artisan key:generate --ansi
    fi
fi

attempt=0
until php artisan db:monitor --databases=mysql > /dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "entrypoint: database still unreachable after $attempt attempts" >&2
        exit 1
    fi
    echo "entrypoint: waiting for the database ($attempt)"
    sleep 2
done

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --isolated
fi

if [ "${APP_ENV:-local}" = "production" ]; then
    php artisan optimize
fi

exec "$@"
