# 000 Foundation: tech spec

## Versions (checked 2026-11-16)

PHP 8.4 · Laravel 13 · Vue starter kit (Inertia 3, Vue 3, TypeScript, Tailwind 4, Fortify,
Wayfinder, Vite+) · MySQL 8.4 · Redis 8.8 · Node 24 LTS · FrankenPHP 1.13 · Pest 5 · Larastan 3 ·
Horizon 5 · Pulse 1 · brick/math 1.

## Scaffolding

- Copy the official starter kit (`laravel/vue-starter-kit`, main branch), rename the package to
  `pixelforge-studio`, run `php artisan install:features` keeping only `registration` (ADR 0020).
- Remove the Vite+ `lint`/`fmt` sections; add ESLint and Prettier (ADR 0018).

## Docker (ADR 0016)

`Dockerfile` targets:

| Stage      | Base                          | Purpose                                                           |
| ---------- | ----------------------------- | ----------------------------------------------------------------- |
| `base`     | `dunglas/frankenphp:1.13-php8.4` | PHP extensions (`pdo_mysql`, `redis`, `intl`, `pcntl`, `zip`, `bcmath`, `opcache`), non-root `app` user, Caddyfile, entrypoint. |
| `dev`      | `base`                        | Adds Composer and Node 24. Used by every dev service.             |
| `vendor`   | `base` + Composer             | `composer install --no-dev`.                                      |
| `assets`   | `vendor` + Node               | `npm ci && npm run build` (Wayfinder needs PHP and the vendor dir). |
| `prod`     | `base`                        | App code + vendor + `public/build`; OPcache without timestamp checks. Default target. |

- FrankenPHP serves `public/` in classic mode on port 8080 (`SERVER_NAME=:8080`), as user `app`.
- `HEALTHCHECK` calls `/health`.
- `docker/entrypoint.sh`: in dev, installs Composer deps and creates `.env` with a key if missing;
  waits for MySQL; runs `migrate --force` when `RUN_MIGRATIONS=true`; caches config, routes, views
  and events when `APP_ENV=production`; then `exec "$@"`.

`compose.yaml` (dev): `app` (port 8080, runs migrations), `horizon`, `scheduler` (`schedule:work`),
`mysql` (with an init script that creates the `testing` database), `redis`, `vite` (port 5173,
`node_modules` in a named volume) and `tunnel` (cloudflared, profile `tunnel`).

`compose.prod.yaml`: same image built with target `prod`, no bind mounts and no Vite,
`restart: unless-stopped`, CPU/memory limits, named volumes for MySQL, Redis and `storage/app`.

`Makefile`: `up`, `down`, `test`, `lint`, `analyse`, `fix`, `shell`, `fresh`, all through
`docker compose exec`.

## Configuration

- `config/services.php` → `pixelforge.base_url`, `api_key`, `webhook_secret`, `webhook_tolerance`.
- `config/billing.php` → `markup_percent` (`BILLING_MARKUP_PERCENT`, default 25).
- `.env.example`: every variable commented, MySQL/Redis wired to the compose services, queue, cache
  and session on Redis, `LOG_CHANNEL=json`. No secrets.

## Application skeleton

- `app/Enums/GenerationStatus` (`queued`, `processing`, `succeeded`, `failed`, `isFinal()`),
  `ImageSize` (`1024x1024`, `1536x1024`, `1024x1536`), `ImageQuality` (`low`, `medium`, `high`).
- `app/Support/Money/Money`: `final readonly`, one `int $nanos`.
  - `ofNanos(int)`, `zero()`, `fromDecimal(string)` (exact USD string, ≤ 9 decimals, else throws),
    `perTokenFromRatePerMillion(string)` (USD per 1M tokens → nanos per token, must be exact).
  - `plus(Money)`, `times(int $tokens)`, `withMarkup(string $percent)` (half-up to the nano).
  - `toDecimalString()` (`"0.046125000"`), `format(int $decimals = 4)` (`"$0.0461"`, half-up),
    `equals(Money)`.
  - Arithmetic through `brick/math` `BigInteger`/`BigDecimal`; overflow beyond `int` throws.
- `app/Services/Pixelforge/PixelforgeClient` (empty interface, methods arrive in spec 001),
  `HttpPixelforgeClient` (holds base URL and API key from config), `FakePixelforgeClient` (for
  tests). `AppServiceProvider` binds the interface to `HttpPixelforgeClient`.
- Empty `app/Data`, `app/Jobs`, `app/Policies` folders (`.gitkeep`).

## Security

- `SecurityHeaders` global middleware: `Content-Security-Policy` with a per-request nonce (Vite
  `useCspNonce`, plus the Vite dev server origin when hot), `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`,
  `Permissions-Policy`, and `Strict-Transport-Security` in production only.
- Session cookie `Secure` in production (`SESSION_SECURE_COOKIE`), `HttpOnly` always, `SameSite=lax`.
- Trusted proxies: private network ranges (Docker network, reverse proxy on the host).
- Rate limiters in `AppServiceProvider`: `generations` (10/min per user, used by spec 004) and
  `webhooks` (120/min per IP). Fortify keeps its `login` limiter (5/min per email + IP).
- Stateless routes registered in `bootstrap/app.php` (`then:`), outside the `web` group: `/health`
  and the `routes/webhooks.php` group (`/webhooks/*`, `throttle:webhooks`, empty until spec 003).
- `users.is_admin` (boolean, default false); `Gate::define('admin')`; `viewHorizon` and `viewPulse`
  delegate to it, with no "local environment" bypass.

## Observability

- `logging.channels.json`: Monolog `StreamHandler` on `php://stdout` with `JsonFormatter`.
- `AssignRequestContext` middleware (web group and stateless routes): `request_id` from
  `X-Request-Id` or a UUID, `user_id` when authenticated, echoed back as `X-Request-Id`. Laravel
  `Context` adds it to every log line and dehydrates it into queued jobs.
- `HealthController`: `SELECT 1` and Redis `PING`; `200 {"status":"ok","checks":{…}}` or `503`.
- Horizon (one `default` supervisor), Pulse (database storage, Redis ingest not needed yet),
  `horizon:snapshot` every five minutes in the scheduler.
- Sentry: `sentry/sentry-laravel`, `Integration::handles($exceptions)`; inert without a DSN.
- `Queue::failing` listener logs failed jobs at error level (context included automatically).

## Quality

- Pest 5 with `pest-plugin-laravel`. `phpunit.xml` points tests at MySQL database `testing`
  (ADR 0017), queue `sync`, cache `array`.
- Architecture tests: strict types in `App`; final classes in our namespaces; controllers do not use
  `Http` facade, Guzzle or `HttpPixelforgeClient`; `Support/Money` has no float casts or float
  functions.
- Smoke tests: `/`, `/login`, `/health` answer 200.
- Larastan level `max` on `app`, `config`, `database`, `routes`, `bootstrap/app.php`.
- Pint `laravel` preset plus `declare_strict_types`.
- ESLint (flat config) + Prettier + `vue-tsc --noEmit`.
- `.github/workflows/ci.yml`: backend job (MySQL + Redis services: Pint, Larastan, Pest,
  `composer audit`), frontend job (ESLint, Prettier, `vue-tsc`, build, `npm audit`), Docker build job.

## Risks

- Vite+ is new; if it misbehaves in Docker we fall back to plain Vite (record an ADR).
- Pulse uses Livewire, which needs inline scripts; the CSP nonce must be passed to it.
