# 0022. Single-VPS deployment behind the shared edge proxy

Status: accepted (2026-11-17)

## Context

The brief needs a public URL, and Pixelforge only delivers webhooks to public URLs. The maintainer's
VPS already runs other projects behind a shared edge proxy (`nginx` in `/home/deploy/edge`, external
Docker network `edge`) that owns ports 80 and 443 and terminates TLS with certbot certificates.

## Decision

- The production stack (`compose.prod.yaml`, same image as development, ADR 0016) runs on that VPS as
  the `aiflow` user, in `~/pixelforge-studio`, and publishes no host port.
- Only the web service joins the `edge` network. It is named `pixelforge-app`, never `app`: Compose
  registers the service name as a DNS name on every network, and other projects' nginx containers
  reach their own PHP as `app`.
- The edge proxy terminates TLS for `pixelforge.matheusserpa.online` with its own Let's Encrypt
  certificate (renewed by the edge's nightly `renew.sh`) and forwards to `pixelforge-app:8080`.
- Trusted proxies come from `TRUSTED_PROXIES` (default: private networks). That is safe only because
  nothing else can reach the app; the real client address is the last untrusted entry nginx appends.
- `make deploy DEPLOY_HOST=<ssh destination>` pulls `main` on the server, rebuilds there, waits for
  every healthcheck and checks `/health` through the public URL. No registry, no CI deploy.
- Admins are promoted with `php artisan users:make-admin`; the seeder refuses to run in production.

## Consequences

- One command ships a version; a deploy costs a few seconds of `502`, covered by webhook retries and
  reconciliation.
- The TLS config and certificate live outside this repository, in the edge proxy; changes there must
  edit `default.conf` in place and pass `nginx -t` before a reload (see `docs/deployment.md`).
- No backups yet; the data lives in the `mysql`, `redis` and `storage` volumes.
  Spec: [005-deployment](../specs/005-deployment/).
