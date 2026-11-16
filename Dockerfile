# syntax=docker/dockerfile:1.7

# One image for every role (web, Horizon, scheduler). See docs/adr/0016-docker-frankenphp.md.
#
#   base    -> FrankenPHP + PHP extensions + non-root user
#   dev     -> base + Composer + Node (local development, code bind-mounted)
#   vendor  -> production Composer dependencies
#   assets  -> front-end build (needs PHP because Wayfinder generates route helpers)
#   prod    -> slim final image (default target)

ARG PHP_IMAGE=dunglas/frankenphp:1.13-php8.4-trixie
ARG NODE_IMAGE=node:24-trixie-slim
ARG COMPOSER_IMAGE=composer:2.11

FROM ${COMPOSER_IMAGE} AS composer
FROM ${NODE_IMAGE} AS node

# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS base

ENV SERVER_NAME=:8080 \
    SERVER_ROOT=/app/public \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

WORKDIR /app

RUN install-php-extensions pdo_mysql redis intl pcntl zip bcmath opcache \
    && useradd --uid 1000 --create-home --shell /bin/bash app \
    && mkdir -p /config/caddy /data/caddy \
    && chown -R app:app /app /config /data

COPY docker/php/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=6 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8080/up') === false ? 1 : 0);"

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---------------------------------------------------------------------------
FROM base AS dev

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && mkdir -p /app/node_modules \
    && chown app:app /app/node_modules

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

USER app

# ---------------------------------------------------------------------------
FROM base AS vendor

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

USER app
COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY --chown=app:app . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

# ---------------------------------------------------------------------------
FROM vendor AS assets

USER root
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
USER app

COPY --chown=app:app package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
RUN npm run build

# ---------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=production \
    APP_DEBUG=false

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/prod.ini "$PHP_INI_DIR/conf.d/zz-prod.ini"

USER app
COPY --chown=app:app --from=vendor /app /app
COPY --chown=app:app --from=assets /app/public/build /app/public/build
