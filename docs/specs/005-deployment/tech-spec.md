# 005 Deployment: tech spec

Status: **approved** · 2026-11-17 (revised: shared edge proxy) · PRD: [prd.md](prd.md)

Deploys the existing `prod` image (ADR 0016) to the maintainer's existing VPS at
`https://pixelforge.matheusserpa.online`. No application behaviour changes for users; the changes are
the production Compose file, trusted proxies, an admin command, a seeder guard, a deploy script, a
runbook and one server block on the VPS's shared edge proxy.

## Revision (2026-11-17, after T1 and T2)

The first version had FrankenPHP's Caddy terminate TLS on ports 80/443. Inspecting the VPS showed
those ports already belong to a shared **edge proxy**: an `nginx:1.28.3-alpine` container from
`/home/deploy/edge` (Compose project `edge`, owned by the `deploy` user), on the external Docker
network `edge`, serving `matheusserpa.online`, `hoop-ia.` and `zap.matheusserpa.online` and
`saberecommerce.com`. Each project joins the `edge` network and the proxy forwards its domain to the
project's web container by name. Certificates are issued with certbot (webroot) into
`/home/deploy/edge/certbot/conf`, renewed nightly by `/home/deploy/edge/renew.sh` (cron of `deploy`),
which renews every certificate there and reloads nginx.

So Decisions 1–4 change: the app joins that network and the edge proxy terminates TLS. T1's
`setcap`, Caddy volumes, published ports, `SERVER_NAME` override and `TRUSTED_PROXIES: ''` are
undone by T3. T1's configurable trusted proxies and everything in T2 stay. The SSH user is `aiflow`
(alias `aiflow` on the maintainer's machine, now in the `docker` group); it cannot write `/opt`, so
the app lives in `~/pixelforge-studio`.

Proposed ADR (written at close, not in the tasks): **0022. Single-VPS deployment behind the shared
edge proxy, with a manual deploy command.** Context: the brief needs a public URL and Pixelforge
needs a public webhook URL; the VPS and its edge proxy already exist. Decision: the content of
"Decisions this spec locks" below. Consequences: one command to ship, a few seconds of downtime per
deploy covered by webhook retries and reconciliation, no backups yet, the image is built on the
server, and the edge proxy (outside this repository) holds the TLS config and certificate. At close also:
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

1. **TLS by the shared edge proxy.** The edge nginx terminates TLS for
   `pixelforge.matheusserpa.online`, redirects HTTP to HTTPS, and forwards to
   `http://pixelforge-app:8080` over the `edge` network. FrankenPHP keeps its plain `:8080` (the image
   default); the app publishes no port on the host.
2. **The app joins the `edge` network** (external, name `${EDGE_NETWORK:-edge}`) with the alias
   `pixelforge-app`, unique on that network (other projects use `app` and `nginx` service names).
   Only the `app` service joins it; Horizon, the scheduler, MySQL and Redis stay on the project's
   default network.
3. **Its own certificate.** A separate Let's Encrypt certificate named
   `pixelforge.matheusserpa.online`, issued by the same certbot webroot flow. The existing
   `matheusserpa.online` certificate is not touched, so the other sites cannot break. `renew.sh`
   already renews every certificate in that folder.
4. **Trusted proxies are configuration.** `config/trustedproxy.php` reads `TRUSTED_PROXIES`
   (comma-separated IPs/CIDRs; empty trusts nobody). Unset → the private list. Production leaves it
   unset: the app publishes no port, so only containers on its Docker networks (the edge proxy) can
   reach it. The edge proxy appends the real client address to `X-Forwarded-For`; Symfony reads the
   chain from the right and skips trusted addresses, so a forged header from a client cannot change
   `request()->ip()`, and `X-Forwarded-Proto: https` makes URLs and secure cookies correct.
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

`APP_DOMAIN` (added in T1) is removed. After the Redis/session block:

```dotenv
# IPs or CIDRs allowed to set X-Forwarded-* headers; empty trusts nobody. Unset: private networks
# (Docker, the dev tunnel, the production edge proxy). Production leaves it unset.
# TRUSTED_PROXIES=
# Production only: external Docker network of the VPS's edge proxy (compose.prod.yaml).
# EDGE_NETWORK=edge
```

`APP_PORT`'s comment says it is development only.

### `compose.prod.yaml`

- Keep from T1: the `x-logging` anchor on every service, and `horizon` / `scheduler` depending on
  `app: condition: service_healthy` with full `depends_on` maps.
- `app`: `environment` keeps only `RUN_MIGRATIONS: 'true'` (remove `SERVER_NAME` and
  `TRUSTED_PROXIES`); no `ports`; volumes back to `storage:/app/storage/app` only; `networks`:
  `default: {}` and `edge: { aliases: [pixelforge-app] }`.
- Top-level `networks`: `edge: { name: '${EDGE_NETWORK:-edge}', external: true }`.
- Top-level `volumes`: remove `caddy_data`, `caddy_config`.
- The header comment explains: served behind the VPS edge proxy (`docs/deployment.md`), the `edge`
  network must exist, no host port is published, `make deploy`.

### `Dockerfile`

Revert T1's `setcap` call in `base`: nothing binds below 1024 anymore. The `Dockerfile` ends up as it
was before this spec.

### Edge proxy changes (on the VPS, outside the repository)

Applied in T4 only after the maintainer approves them in chat; documented in `docs/deployment.md` by
T3. The file is `/home/deploy/edge/nginx/default.conf` (owned by `deploy`). In order:

1. Add `pixelforge.matheusserpa.online` to the `server_name` list of the existing port-80 redirect
   block for `matheusserpa.online` (the one that serves `/.well-known/acme-challenge/` from
   `/var/www/certbot`), then `docker compose exec nginx nginx -t && docker compose exec nginx nginx -s
   reload` in `/home/deploy/edge`.
2. Issue the certificate:

   ```sh
   cd /home/deploy/edge
   docker run --rm \
     -v "$PWD/certbot/conf:/etc/letsencrypt" \
     -v "$PWD/certbot/www:/var/www/html/public" \
     certbot/certbot:latest certonly --webroot -w /var/www/html/public \
     --cert-name pixelforge.matheusserpa.online -d pixelforge.matheusserpa.online \
     --non-interactive --agree-tos --no-eff-email --register-unsafely-without-email
   ```

   (The existing ACME account is reused; the flags only matter if none is found.)
3. Add the HTTPS block, in the same style as the other projects, then `nginx -t` and reload:

   ```nginx
   # ----------------------------------------------------------------------------- pixelforge
   server {
       listen 443 ssl;
       http2 on;
       server_name pixelforge.matheusserpa.online;

       ssl_certificate /etc/letsencrypt/live/pixelforge.matheusserpa.online/fullchain.pem;
       ssl_certificate_key /etc/letsencrypt/live/pixelforge.matheusserpa.online/privkey.pem;

       set $pixelforge http://pixelforge-app:8080;

       location / {
           proxy_http_version 1.1;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto https;
           proxy_pass $pixelforge;
           proxy_read_timeout 60s;
       }
   }
   ```

The `resolver 127.0.0.11` and `set $var` pattern already in that file let nginx start even when the
app is down (it answers `502` until the app is up).

### `scripts/deploy.sh` (new) and `make deploy`

POSIX `sh`, `set -eu`, runs on the maintainer's machine (Git Bash on Windows, any Unix shell).

| Variable      | Default                                        | Meaning                                    |
| ------------- | ---------------------------------------------- | ------------------------------------------ |
| `DEPLOY_HOST` | required                                       | SSH destination (`user@host` or an alias). |
| `DEPLOY_PATH` | `pixelforge-studio` (T3; was `/opt/...`)       | Clone on the server, relative to the SSH user's home. |
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

Sections, short and command-first. T3 rewrites what T2 wrote about Caddy, ports 80/443,
`APP_DOMAIN`, `/opt` and `caddy_data` to match this revision, and adds an **Edge proxy** section with
the three steps of "Edge proxy changes" above.

1. **Requirements**: VPS with Docker Engine + Compose plugin, an SSH user in the `docker` group, the
   edge proxy running with its `edge` network, ~3 GB RAM for the stack plus headroom for builds, DNS
   `A` record of the domain pointing to the VPS.
2. **Pixelforge**: create a production API key, set its webhook URL to
   `https://<domain>/webhooks/pixelforge`, copy the key and the `whsec_…` secret. Never reuse the
   development key (`GET /v1/usage` must match only production).
3. **First install**: `git clone` into `~/pixelforge-studio`, `cp .env.example .env`,
   `chmod 600 .env`, and the production values to change: `APP_ENV=production`, `APP_DEBUG=false`,
   `APP_KEY` (`echo "base64:$(openssl rand -base64 32)"`), `APP_URL=https://<domain>`,
   `LOG_LEVEL=info`, strong `DB_PASSWORD` and `DB_ROOT_PASSWORD`,
   `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, optional `SENTRY_LARAVEL_DSN`. Then the first
   `make deploy` from the maintainer's machine (or the same compose command on the server).
4. **Admin**: register in the browser, then
   `docker compose -f compose.prod.yaml exec app php artisan users:make-admin <email>`.
5. **Ship a version**: push to `main`, CI green, `make deploy DEPLOY_HOST=<ssh destination>`. What
   happens during the few seconds of downtime (webhooks retried, reconciliation).
6. **Operate**: health URL, `docker compose -f compose.prod.yaml ps`, `logs -f app horizon`,
   Horizon and Pulse URLs, where data lives (volumes `mysql`, `redis`, `storage`) and
   that `down -v` destroys it.
7. **Never in production**: `db:seed`, `migrate:fresh`, `make fresh`.

## Behaviour in code

- Development is unchanged: `compose.yaml`, the `dev` target and `SERVER_NAME=:8080` stay; unset
  `TRUSTED_PROXIES` keeps today's list.
- In production nothing of this stack publishes a host port; the edge proxy reaches the web
  container as `pixelforge-app:8080` on the `edge` network.
- A deploy rebuilds the image, recreates changed containers, waits for every healthcheck, and fails
  loudly if the public health check fails. Volumes are never removed by the script.

## Tests

| Test | Task |
| ---- | ---- |
| Feature: with `trustedproxy.proxies` set to `''`, a request from `REMOTE_ADDR` `172.18.0.5` carrying `X-Forwarded-For: 203.0.113.9` sees `$request->ip()` = `172.18.0.5`. With the default list, it sees `203.0.113.9`. Use a route registered inside the test that returns the IP. | T1 |
| Smoke (manual, reported in the task, not in Pest): `docker compose -f compose.prod.yaml config` is valid with `APP_DOMAIN` set and fails with a clear message without it; the `prod` image builds; `getcap /usr/local/bin/frankenphp` inside it shows `cap_net_bind_service`. | T1 |
| Feature: `users:make-admin` promotes an existing user (exit 0, `is_admin` true), is a no-op for an admin, fails with exit 1 and the message for an unknown email. | T2 |
| Feature: `DatabaseSeeder` creates no users when the environment is `production` (`app()->detectEnvironment(fn () => 'production')` or equivalent, restored after), and still creates both in `testing`. | T2 |
| Feature (T3): with the default list, a request from the edge proxy (`REMOTE_ADDR` `172.21.0.4`) carrying `X-Forwarded-For: 10.9.9.9, 203.0.113.9` (a forged private entry followed by the address nginx appended) sees `$request->ip()` = `203.0.113.9`, and `X-Forwarded-Proto: https` makes `$request->isSecure()` true. | T3 |
| Smoke (T3, manual): `docker compose -f compose.prod.yaml config` renders no `ports`, the `edge` network as external with the `pixelforge-app` alias on `app` only, no Caddy volumes; it does not ask for `APP_DOMAIN`. The `prod` image builds. | T3 |
| Live checklist (main session with the maintainer, recorded in T4): the PRD acceptance list. | T4 |

The T1 smoke row is superseded by T3's. The local smoke run must not start the production stack on
top of the development one: if the implementer boots it at all, use a separate project name
(`-p pixelforge-smoke`), create the `edge` network for it, and remove both afterwards. Booting it is
optional; the `config` and image checks are required.

## Risks

- **Breaking the other sites on the edge proxy.** One bad edit to the shared `default.conf` takes
  down every site. Mitigation: always `nginx -t` before `nginx -s reload` (a failed test leaves the
  running config in place), a separate certificate instead of expanding the shared one, and a copy
  of `default.conf` taken before editing. The maintainer approves the change in chat before it is
  applied.
- **Writing as `aiflow` into `deploy`'s folder.** `aiflow` has no write access to `/home/deploy/edge`;
  through the `docker` group it can, but that bypasses file ownership. T4 asks the maintainer whether
  to apply the edge changes that way (files kept owned by uid 1000) or to do them as `deploy`/root.
- **Certificate issuance fails.** The ACME challenge only works once step 1 of the edge changes is
  live; the DNS already points to the VPS. Let's Encrypt rate limits failed validations, so check
  `curl http://<domain>/.well-known/acme-challenge/x` reaches the edge (a `404`, not a closed
  connection) before running certbot.
- **Memory on a shared VPS.** Limits add up to ~3 GB; the build (Composer, `npm ci`, Vite) needs
  ~2 GB more while it runs. Check `free -h` in T3; on pressure, build with the stack running is still
  fine because the old containers keep serving until recreated.
- **Downtime during a deploy.** A few seconds while `app` is recreated. Webhooks are retried by
  Pixelforge and reconciliation polls stuck generations; queued jobs wait in Redis (persisted with
  AOF). Horizon stops gracefully (`stop_grace_period: 60s`).
- **Losing data.** Only `down -v` or deleting volumes loses it; the runbook says so. No backups in
  scope.
- **Trusted proxies misconfigured.** Trusting the private ranges is safe only while the app
  publishes no host port; if a port is ever published, clients could forge their IP. The T3 smoke
  check (no `ports`) and the comment in `compose.prod.yaml` guard it.
- **Secrets.** `.env` lives only on the server with mode 600; nothing secret goes into the
  repository, the script or GitHub.
