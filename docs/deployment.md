# Deployment runbook

The app runs from `compose.prod.yaml` on a single VPS, behind the VPS's shared **edge proxy** (an
nginx container that owns ports 80/443 and terminates HTTPS for every site on the server). Public
URL: `https://pixelforge.matheusserpa.online`.

```
browser --HTTPS--> edge nginx --HTTP--> pixelforge-app:8080 (network "edge")
```

The app publishes no host port. The edge proxy reaches the web container as
`pixelforge-app` (the Compose service name, which must stay unique on that network) on the external Docker network `edge`; Horizon, the scheduler, MySQL and Redis stay
on the project's own network.

## 1. Requirements

- A VPS with Docker Engine and the Compose plugin, and an SSH user in the `docker` group.
- The edge proxy running, with its external Docker network (`docker network ls` shows `edge`).
- About 3 GB of RAM for the stack, plus about 2 GB while an image builds. Check with `free -h`.
- The DNS `A` (and `AAAA`) record of the domain points to the VPS. Check with `dig +short <domain>`.

## 2. Pixelforge

1. Create a production API key. Never reuse the development key, so `GET /v1/usage` matches only
   production.
2. Set that key's webhook URL to `https://<domain>/webhooks/pixelforge`.
3. Keep the key and the `whsec_...` signing secret for the next step.

## 3. Edge proxy

Changes on the VPS, outside this repository. The edge files live in `/home/deploy/edge`. Take a copy
of `nginx/default.conf` first, and always run `nginx -t` before reloading: a failed test leaves the
running config in place, while a bad config would take every site down.

`default.conf` is bind-mounted into the container as a single file, so edit it **in place** (an
editor that rewrites the same file, or `cat new.conf > default.conf`). Tools that replace the file
(`sed -i`, `mv`) give it a new inode: the container keeps seeing the old file, `nginx -t` passes on
it and the reload changes nothing. If that happens, test the new file in a throwaway container
(`docker run --rm -v /home/deploy/edge/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro -v
/home/deploy/edge/certbot/conf:/etc/letsencrypt:ro nginx:1.28.3-alpine nginx -t`) and then
`docker restart edge-nginx-1` (about a second of downtime for every site).

1. In `nginx/default.conf`, add `pixelforge.matheusserpa.online` to the `server_name` list of the
   port-80 block that serves `/.well-known/acme-challenge/` from `/var/www/certbot`, then:

   ```sh
   cd /home/deploy/edge
   docker compose exec nginx nginx -t && docker compose exec nginx nginx -s reload
   ```

   Check that the challenge path reaches the edge (a `404`, not a closed connection) before asking
   for a certificate: `curl -i http://pixelforge.matheusserpa.online/.well-known/acme-challenge/x`.

2. Issue a certificate of its own (the other sites' certificates are not touched; the nightly
   `renew.sh` renews every certificate in that folder):

   ```sh
   cd /home/deploy/edge
   docker run --rm \
     -v "$PWD/certbot/conf:/etc/letsencrypt" \
     -v "$PWD/certbot/www:/var/www/html/public" \
     certbot/certbot:latest certonly --webroot -w /var/www/html/public \
     --cert-name pixelforge.matheusserpa.online -d pixelforge.matheusserpa.online \
     --non-interactive --agree-tos --no-eff-email --register-unsafely-without-email
   ```

3. Add the HTTPS block to `nginx/default.conf`, then run the `nginx -t` and reload commands again:

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
           proxy_buffer_size 32k;
           proxy_buffers 8 32k;
           proxy_busy_buffers_size 64k;
       }
   }
   ```

   `proxy_pass $pixelforge` relies on the `resolver 127.0.0.11` already declared in that file (Docker's
   DNS); with it, the `set $var` pattern lets nginx start even when the app is down, and it answers
   `502` until the app is up. Check the `resolver` line is there before reloading. The larger proxy
   buffers are required: Laravel's `Link` preload headers plus session cookies exceed nginx's default
   header buffer (`upstream sent too big header`, `502`).

## 4. First install

On the server, as the SSH user:

```sh
git clone https://github.com/matheusserpa7/pixelforge-image-generator ~/pixelforge-studio
cd ~/pixelforge-studio
cp .env.example .env
chmod 600 .env
```

Edit `.env` and change:

| Variable                          | Value                                                |
| --------------------------------- | ---------------------------------------------------- |
| `APP_ENV`                         | `production`                                         |
| `APP_DEBUG`                       | `false`                                              |
| `APP_KEY`                         | output of `echo "base64:$(openssl rand -base64 32)"` |
| `APP_URL`                         | `https://<domain>`                                   |
| `LOG_LEVEL`                       | `info`                                               |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | strong, unique values                                |
| `PIXELFORGE_API_KEY`              | the production key                                   |
| `PIXELFORGE_WEBHOOK_SECRET`       | the production `whsec_...` secret                    |
| `SENTRY_LARAVEL_DSN`              | optional                                             |

Leave `TRUSTED_PROXIES` unset: the app publishes no port, so only containers on its Docker networks
(the edge proxy) can reach it, and the edge proxy sets the real client address.

Then, from the maintainer's machine (see section 6):

```sh
make deploy DEPLOY_HOST=<ssh destination>
```

or on the server: `docker compose -f compose.prod.yaml up -d --build --wait --remove-orphans`.

## 5. Admin

Register in the browser, then promote the account:

```sh
docker compose -f compose.prod.yaml exec pixelforge-app php artisan users:make-admin <email>
```

Admins can open `/horizon` and `/pulse`; regular users get `403`. No account is seeded in production.

## 6. Ship a version

1. Push to `main` and wait for CI to be green.
2. Run `make deploy DEPLOY_HOST=<ssh destination>` (or `DEPLOY_HOST=... make deploy`).
   Optional: `DEPLOY_PATH` (default `pixelforge-studio`, relative to the SSH user's home),
   `DEPLOY_URL`.

The script pulls `main` on the server, rebuilds, recreates changed containers, waits for their
healthchecks and ends by calling `<url>/health`: `Healthy` or `Health check failed`.

A deploy interrupts the web app for a few seconds. Pixelforge retries failed webhook deliveries,
reconciliation polls stuck generations every minute, and queued jobs wait in Redis. Horizon stops
gracefully (up to 60 s).

## 7. Operate

- Health: `curl https://<domain>/health`
- Status: `docker compose -f compose.prod.yaml ps`
- Logs: `docker compose -f compose.prod.yaml logs -f pixelforge-app horizon` (rotated: 5 files of 10 MB each)
- Dashboards: `https://<domain>/horizon` and `https://<domain>/pulse` (admin only)
- Certificate: issued and renewed by the edge proxy's `renew.sh`, not by this stack.
- Data lives in named volumes: `mysql`, `redis` and `storage` (generated images).
  `docker compose down -v` destroys all of them. There are no backups.

## 8. Never in production

`db:seed`, `migrate:fresh`, `make fresh`, `docker compose down -v`. Never publish a host port for the
app: the trusted-proxy default would let clients forge their IP.
