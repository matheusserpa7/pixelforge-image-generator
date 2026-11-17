# Deployment runbook

The app runs from `compose.prod.yaml` on a single VPS. FrankenPHP's built-in Caddy terminates HTTPS
(Let's Encrypt, renewed automatically) on ports 80/443. Public URL:
`https://pixelforge.matheusserpa.online`.

## 1. Requirements

- A VPS with Docker Engine and the Compose plugin.
- Ports 80 and 443 (TCP, plus UDP 443 for HTTP/3) open and free. Check with `ss -tlnp`.
- About 3 GB of RAM for the stack, plus about 2 GB while an image builds. Check with `free -h`.
- The DNS `A` (and `AAAA`) record of the domain points to the VPS before the first start. Check with
  `dig +short <domain>`. Otherwise the certificate request fails and Let's Encrypt rate limits retries.

## 2. Pixelforge

1. Create a production API key. Never reuse the development key, so `GET /v1/usage` matches only
   production.
2. Set that key's webhook URL to `https://<domain>/webhooks/pixelforge`.
3. Keep the key and the `whsec_...` signing secret for the next step.

## 3. First install

On the server:

```sh
git clone https://github.com/matheusserpa7/pixelforge-image-generator /opt/pixelforge-studio
cd /opt/pixelforge-studio
cp .env.example .env
chmod 600 .env
```

Edit `.env` and change:

| Variable                                  | Value                                         |
| ----------------------------------------- | --------------------------------------------- |
| `APP_ENV`                                 | `production`                                  |
| `APP_DEBUG`                               | `false`                                       |
| `APP_KEY`                                 | output of `echo "base64:$(openssl rand -base64 32)"` |
| `APP_URL`                                 | `https://<domain>`                            |
| `APP_DOMAIN`                              | `<domain>`                                    |
| `LOG_LEVEL`                               | `info`                                        |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD`         | strong, unique values                         |
| `PIXELFORGE_API_KEY`                      | the production key                            |
| `PIXELFORGE_WEBHOOK_SECRET`               | the production `whsec_...` secret             |
| `SENTRY_LARAVEL_DSN`                      | optional                                      |

Then, from the maintainer's machine (see section 5):

```sh
make deploy DEPLOY_HOST=<ssh destination>
```

or on the server: `docker compose -f compose.prod.yaml up -d --build --wait`.

## 4. Admin

Register in the browser, then promote the account:

```sh
docker compose -f compose.prod.yaml exec app php artisan users:make-admin <email>
```

Admins can open `/horizon` and `/pulse`; regular users get `403`. No account is seeded in production.

## 5. Ship a version

1. Push to `main` and wait for CI to be green.
2. Run `make deploy DEPLOY_HOST=<ssh destination>` (or `DEPLOY_HOST=... make deploy`).
   Optional: `DEPLOY_PATH` (default `/opt/pixelforge-studio`), `DEPLOY_URL`.

The script pulls `main` on the server, rebuilds, recreates changed containers, waits for their
healthchecks and ends by calling `<url>/health`: `Healthy` or `Health check failed`.

A deploy interrupts the web app for a few seconds. Pixelforge retries failed webhook deliveries,
reconciliation polls stuck generations every minute, and queued jobs wait in Redis. Horizon stops
gracefully (up to 60 s).

## 6. Operate

- Health: `curl https://<domain>/health`
- Status: `docker compose -f compose.prod.yaml ps`
- Logs: `docker compose -f compose.prod.yaml logs -f app horizon` (rotated: 5 files of 10 MB each)
- Dashboards: `https://<domain>/horizon` and `https://<domain>/pulse` (admin only)
- Data lives in named volumes: `mysql`, `redis`, `storage` (generated images) and `caddy_data`
  (certificates). `docker compose down -v` destroys all of them. There are no backups.

## 7. Never in production

`db:seed`, `migrate:fresh`, `make fresh`, `docker compose down -v`.
