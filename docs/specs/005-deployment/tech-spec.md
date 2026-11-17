# 005 Deployment: tech spec

Status: **approved** · 2026-11-17 · PRD: [prd.md](prd.md)

Deploys the existing `prod` image (ADR 0016) to the maintainer's existing VPS at
`https://pixelforge.matheusserpa.online`. No application behaviour changes for users; the changes are
the production Compose file, the image, trusted proxies, an admin command, a seeder guard, a deploy
script and a runbook.

Proposed ADR (written at close, not in the tasks): **0022. Single-VPS deployment with FrankenPHP's
automatic HTTPS and a manual deploy command.** Context: the brief needs a public URL and Pixelforge
needs a public webhook URL; the VPS already exists. Decision: the content of "Decisions this spec
locks" below. Consequences: one command to ship, a few seconds of downtime per deploy covered by
webhook retries and reconciliation, no backups yet, the image is built on the server. At close also:
README "Run it" production paragraph points to `docs/deployment.md` and the public URL, `CLAUDE.md`
commands table gets `make deploy`, roadmap 005 `Done` and 006 `Next`, README status line.
`docs/product/` does not change. Do not edit `docs/product/`, `docs/adr/`, `docs/roadmap.md`,
`README.md` or `CLAUDE.md` in the tasks.

## Technical context

- `Dockerfile`: stages `base` → `dev` / `vendor` → `assets` → `prod`. `base` sets
  `SERVER_NAME=:8080`, `XDG_CONFIG_HOME=/config`, `XDG_DATA_HOME=/data` (Caddy's config and
  certificates), creates user `app` (uid 1000) and runs as it in `prod`. The healthcheck calls
  `http://127.0.0.1:8080/health`. The command is `frankenphp run --config /etc/frankenphp/Caddyfile`;
  that Caddyfile's site address is `{$SERVER_NAME}`, substituted before parsing, so it may hold
  several comma-separated addresses.
- `compose.prod.yaml`: `app` (web, `RUN_MIGRATIONS=true`, publishes `${APP_PORT:-8080}:8080`),
  `horizon`, `scheduler`, `mysql`, `redis`, all from the `x-app` anchor with `env_file: .env`,
  `restart: unless-stopped`, resource limits and named volumes `mysql`, `redis`, `storage`
  (`/app/storage/app`, where images live). `horizon` and `scheduler` only depend on MySQL and Redis.
- `docker/entrypoint.sh` waits for MySQL, migrates when `RUN_MIGRATIONS=true`, runs
  `php artisan optimize` in production, then `exec`s the command. The container healthcheck only
  passes after that, so "app healthy" means "migrations done".
- `bootstrap/app.php` calls `$middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8',
  '172.16.0.0/12', '192.168.0.0/16'])`. Fortify throttles login by email + IP; the `webhooks` limiter
  and `throttle` middleware use the IP too.
- Users have `is_admin` (not fillable). Gates `admin`, `viewPulse`, `viewHorizon` use it.
  `DatabaseSeeder` creates `test@example.com` and `admin@example.com` with password `password`.
- `config/session.php`: cookies are `secure` in production by default.
- Webhook route: `POST /webhooks/pixelforge`. Pixelforge retries failed deliveries (10 s, 30 s,
  2 min, ...) and reconciliation polls stuck generations every minute (ADR 0011).
- The host is Windows with Git Bash, Docker and `make`; it has `ssh` and `curl`. The repository is
  public at `https://github.com/matheusserpa7/pixelforge-image-generator`, so the server clones over
  HTTPS without credentials.

### Decisions this spec locks

1. **TLS by FrankenPHP's own Caddy.** In production the `app` container sets
   `SERVER_NAME: '${APP_DOMAIN:?APP_DOMAIN is required}, :8080'`: Caddy obtains and renews a Let's
   Encrypt certificate for the domain, serves it on 443 (TCP and UDP for HTTP/3), redirects 80 to 443,
   and keeps plain `:8080` inside the container for the healthcheck only (8080 is no longer published).
   No reverse proxy on the VPS. Ports 80 and 443 must be free there.
2. **Binding 80/443 as non-root.** The image gives the FrankenPHP binary `CAP_NET_BIND_SERVICE`
   (`setcap`, as FrankenPHP's docs recommend for rootless images); the container keeps running as
   `app`.
3. **Certificates persist** in named volumes `caddy_data` (`/data`) and `caddy_config` (`/config`) on
   `app`, so a redeploy never asks Let's Encrypt again (its rate limits are strict).
4. **Trusted proxies are configuration.** `config/trustedproxy.php` reads `TRUSTED_PROXIES`
   (comma-separated IPs/CIDRs). Unset → the current private list (development and the Cloudflare
   tunnel keep working). In production nothing sits in front of the app, so `compose.prod.yaml` sets
   it to empty on `app`: forwarding headers are never trusted and `request()->ip()` is the real
   client address.
5. **Build on the server.** The deploy pulls `main` on the VPS and runs
   `docker compose -f compose.prod.yaml up -d --build --wait`. No registry.
6. **Start order.** `horizon` and `scheduler` depend on `app` being `service_healthy`, so they never
   run new code against an unmigrated schema.
7. **Log rotation.** Every service logs with the `json-file` driver, `max-size: 10m`, `max-file: 5`.
8. **Admins are promoted, never seeded.** `php artisan users:make-admin {email}` promotes an already
   registered account. `DatabaseSeeder` refuses to run in production.

## Model and contracts

No migration, no new table, no route.

### `config/trustedproxy.php` (new)

```php
return [
    // Comma-separated IPs or CIDRs allowed to set X-Forwarded-* headers. Empty: trust nobody.
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'),
];
```

`bootstrap/app.php` drops the hard-coded `trustProxies(at: [...])` list. Laravel's `TrustProxies`
middleware falls back to `config('trustedproxy.proxies')` when no `at` list is given; confirm that in
`vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustProxies.php` before relying on it. If the
fallback does not exist or mishandles an empty string, keep the same behaviour another way (for
example a small subclass reading the config) and record it here. It must work with `config:cache`
(production runs `optimize`), so read the config at request time, never `env()` outside `config/`.
Keep the trusted headers Laravel uses today.

### `users:make-admin` command (new)

`app/Console/Commands/MakeAdminCommand.php`, signature `users:make-admin {email}`.

- Finds the user by exact email. None → error line `No user with email <email>.`, exit code 1.
- Already admin → info line `<email> is already an admin.`, exit code 0, nothing written.
- Otherwise sets `is_admin = true` with `forceFill(...)->save()` (the attribute is not fillable on
  purpose), prints `<email> is now an admin.`, logs `info` "User promoted to admin" with `user_id`,
  exit code 0.
- No revoke option, no password handling.

### `DatabaseSeeder` guard

At the start of `run()`: when `app()->isProduction()`, print a warning through `$this->command` (when
present) and return without creating anything. Development seeds stay as they are.

### `.env.example` additions

In the Application section, after `APP_URL`:

```dotenv
# Production only: public domain served over HTTPS by FrankenPHP (compose.prod.yaml).
# APP_DOMAIN=pixelforge.example.com
```

After the Redis/session block:

```dotenv
# IPs or CIDRs allowed to set X-Forwarded-* headers. Unset: private networks (Docker, tunnel).
# compose.prod.yaml sets it empty: nothing proxies the app in production.
# TRUSTED_PROXIES=
```

`APP_PORT`'s comment says it is development only.

### `compose.prod.yaml`

- A `x-logging` anchor (`driver: json-file`, options `max-size: '10m'`, `max-file: '5'`) used by every
  service.
- `app`: `environment` gets `SERVER_NAME` (decision 1) and `TRUSTED_PROXIES: ''` next to
  `RUN_MIGRATIONS`; `ports` become `'80:80'`, `'443:443'`, `'443:443/udp'`; volumes add
  `caddy_data:/data` and `caddy_config:/config` (keep `storage`).
- `horizon` and `scheduler`: `depends_on` adds `app: condition: service_healthy` (keeping MySQL and
  Redis). YAML merge keys are shallow, so write their full `depends_on` maps.
- `volumes` adds `caddy_data`, `caddy_config`.
- The header comment explains the domain, ports and `make deploy`.

### `Dockerfile`

In `base`, after `install-php-extensions`: install `libcap2-bin` and run
`setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp` (check the binary path in the image).
Clean the apt lists. Nothing else changes; dev keeps `:8080`.

### `scripts/deploy.sh` (new) and `make deploy`

POSIX `sh`, `set -eu`, runs on the maintainer's machine (Git Bash on Windows, any Unix shell).

| Variable      | Default                                        | Meaning                                    |
| ------------- | ---------------------------------------------- | ------------------------------------------ |
| `DEPLOY_HOST` | required                                       | SSH destination (`user@host` or an alias). |
| `DEPLOY_PATH` | `/opt/pixelforge-studio`                       | Clone of the repository on the server.     |
| `DEPLOY_URL`  | `https://pixelforge.matheusserpa.online`       | Public URL checked at the end.             |

Steps:

1. Missing `DEPLOY_HOST` → usage message, exit 1.
2. `git fetch origin main`; if local `HEAD` differs from `origin/main`, print a warning that only
   pushed commits are deployed (do not stop).
3. One `ssh "$DEPLOY_HOST"` call running, with `set -e`: `cd "$DEPLOY_PATH"`,
   `git pull --ff-only origin main`, `docker compose -f compose.prod.yaml up -d --build --wait
   --remove-orphans`, `docker image prune -f`, then print `Deployed <short sha>`.
4. `curl -fsS --max-time 10 "$DEPLOY_URL/health"`; print the JSON and `Healthy` on success, or
   `Health check failed` and exit 1.

`Makefile`: `deploy` target (in `.PHONY`, with a `##` comment) runs `sh scripts/deploy.sh`; both
`make deploy DEPLOY_HOST=...` and `DEPLOY_HOST=... make deploy` must reach the script (GNU make
exports command-line variables to recipes; check it under Git Bash).
Add `scripts` to `.dockerignore`.

### `docs/deployment.md` (new runbook)

Sections, short and command-first:

1. **Requirements**: VPS with Docker Engine + Compose plugin, ports 80/443 (TCP, plus UDP 443) open
   and free, ~3 GB RAM for the stack plus headroom for builds, DNS `A`/`AAAA` record of the domain
   pointing to the VPS before the first start.
2. **Pixelforge**: create a production API key, set its webhook URL to
   `https://<domain>/webhooks/pixelforge`, copy the key and the `whsec_…` secret. Never reuse the
   development key (`GET /v1/usage` must match only production).
3. **First install**: `git clone` into `/opt/pixelforge-studio`, `cp .env.example .env`,
   `chmod 600 .env`, and the production values to change: `APP_ENV=production`, `APP_DEBUG=false`,
   `APP_KEY` (`echo "base64:$(openssl rand -base64 32)"`), `APP_URL=https://<domain>`,
   `APP_DOMAIN=<domain>`, `LOG_LEVEL=info`, strong `DB_PASSWORD` and `DB_ROOT_PASSWORD`,
   `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, optional `SENTRY_LARAVEL_DSN`. Then the first
   `make deploy` from the maintainer's machine (or the same compose command on the server).
4. **Admin**: register in the browser, then
   `docker compose -f compose.prod.yaml exec app php artisan users:make-admin <email>`.
5. **Ship a version**: push to `main`, CI green, `make deploy DEPLOY_HOST=<ssh destination>`. What
   happens during the few seconds of downtime (webhooks retried, reconciliation).
6. **Operate**: health URL, `docker compose -f compose.prod.yaml ps`, `logs -f app horizon`,
   Horizon and Pulse URLs, where data lives (volumes `mysql`, `redis`, `storage`, `caddy_data`) and
   that `down -v` destroys it.
7. **Never in production**: `db:seed`, `migrate:fresh`, `make fresh`.

## Behaviour in code

- Development is unchanged: `compose.yaml`, the `dev` target and `SERVER_NAME=:8080` stay; unset
  `TRUSTED_PROXIES` keeps today's list.
- In production the web container answers `https://<domain>` and nothing else publicly; MySQL and
  Redis publish no ports.
- A deploy rebuilds the image, recreates changed containers, waits for every healthcheck, and fails
  loudly if the public health check fails. Volumes are never removed by the script.

## Tests

| Test | Task |
| ---- | ---- |
| Feature: with `trustedproxy.proxies` set to `''`, a request from `REMOTE_ADDR` `172.18.0.5` carrying `X-Forwarded-For: 203.0.113.9` sees `$request->ip()` = `172.18.0.5`. With the default list, it sees `203.0.113.9`. Use a route registered inside the test that returns the IP. | T1 |
| Smoke (manual, reported in the task, not in Pest): `docker compose -f compose.prod.yaml config` is valid with `APP_DOMAIN` set and fails with a clear message without it; the `prod` image builds; `getcap /usr/local/bin/frankenphp` inside it shows `cap_net_bind_service`. | T1 |
| Feature: `users:make-admin` promotes an existing user (exit 0, `is_admin` true), is a no-op for an admin, fails with exit 1 and the message for an unknown email. | T2 |
| Feature: `DatabaseSeeder` creates no users when the environment is `production` (`app()->detectEnvironment(fn () => 'production')` or equivalent, restored after), and still creates both in `testing`. | T2 |
| Live checklist (main session with the maintainer, recorded in T3): the PRD acceptance list. | T3 |

The local smoke run must not start the production stack on top of the development one: if the
implementer boots it at all, use a separate project name (`-p pixelforge-smoke`), and
`down -v` it afterwards. Booting it is optional; the `config` and image checks are required.

## Risks

- **Ports 80/443 already used on the shared VPS.** Caddy cannot start; the deploy's `--wait` fails.
  Checked in T3 before the first start (`ss -tlnp`). If taken, this spec goes back to draft for the
  reverse-proxy variant.
- **DNS not pointing to the VPS at first start.** The ACME challenge fails and Caddy retries with
  backoff; Let's Encrypt rate limits failed validations. Check `dig` before the first start.
- **Memory on a shared VPS.** Limits add up to ~3 GB; the build (Composer, `npm ci`, Vite) needs
  ~2 GB more while it runs. Check `free -h` in T3; on pressure, build with the stack running is still
  fine because the old containers keep serving until recreated.
- **Downtime during a deploy.** A few seconds while `app` is recreated. Webhooks are retried by
  Pixelforge and reconciliation polls stuck generations; queued jobs wait in Redis (persisted with
  AOF). Horizon stops gracefully (`stop_grace_period: 60s`).
- **Losing data.** Only `down -v` or deleting volumes loses it; the runbook says so. No backups in
  scope.
- **Trusted proxies misconfigured.** Too wide in production lets clients forge their IP and dodge
  throttles; T1's test and the explicit empty value on `app` cover it.
- **Secrets.** `.env` lives only on the server with mode 600; nothing secret goes into the
  repository, the script or GitHub.
