# 005 Deployment: PRD

Status: **implemented** · 2026-11-17 (revised: shared edge proxy)

## Problem

The app works end to end on a laptop, but the brief requires it to be deployed somewhere public, and
the reviewers need a URL with a few generations left on it. Pixelforge also only delivers webhooks to
public http(s) URLs, so without a public deployment the main robustness path (signed webhooks) only
runs through a development tunnel.

The production stack exists on paper (`compose.prod.yaml`, the `prod` image target) but has never
run: it has no HTTPS, no way to create an operator account without the development seeder, no
repeatable way to ship a new version, and no written procedure for the first install.

## Users

- **Reviewers** of the take-home: open the public URL, register, generate images, watch the history.
- **The operator** (the maintainer): installs the app once on the server, ships new versions,
  promotes their own account to admin to open Horizon and Pulse, and checks health and logs.
- **Pixelforge**: delivers signed webhooks to the public URL.

## In scope

- The app served at `https://pixelforge.matheusserpa.online`, with a valid certificate obtained and
  renewed automatically, HTTP redirected to HTTPS.
- Runs on the maintainer's existing VPS (the one that already hosts the aiflow runner), as the
  `aiflow` user, from the same production image CI already builds, with MySQL, Redis, Horizon and the
  scheduler.
- That VPS already has a shared edge proxy that owns ports 80/443 and serves the maintainer's other
  sites. The app sits behind it like those sites do, with its own certificate, without disturbing
  them.
- Its own Pixelforge API key and signing secret for production, with that key's webhook URL pointing
  to the production webhook endpoint, so `GET /v1/usage` for that key matches only what the deployed
  app did.
- Data that survives restarts and new versions: database, Redis, stored images, certificates.
- One operator command, run from the maintainer's machine, that ships the current `main` to the
  server and reports whether the new version is healthy.
- An operator command on the server that turns an already registered account into an admin. No
  default accounts or default passwords exist in production.
- Client IP addresses that cannot be forged through forwarding headers in production, so login and
  submission throttles hold.
- Container logs that cannot fill the disk.
- A written runbook: first install, configuration, shipping a version, creating the admin, checking
  health and logs.
- The first real deployment, verified live: register, generate, webhook received, charge recorded,
  image shown.

## Out of scope

- Automatic deploys from GitHub Actions, image registries, blue-green or zero-downtime deploys. A
  deploy causes a short interruption; Pixelforge retries webhooks and the reconciliation job covers
  anything missed.
- Database backups, monitoring alerts and log shipping.
- Real email delivery. Password reset emails keep going to the log, as in development.
- Closing registration or adding invite codes. Abuse is bounded by the existing per-user submission
  throttle and Pixelforge's own daily limit.
- Object storage (S3) for images; the local disk on a named volume is enough.
- Server hardening (SSH, firewall policy and OS updates are the maintainer's existing setup).
- Changing how the edge proxy itself works (its image, renewal cron, other sites).

## Acceptance

1. `https://pixelforge.matheusserpa.online` loads with a valid certificate; `http://` redirects to
   `https://`.
2. `https://pixelforge.matheusserpa.online/health` answers `200` with MySQL and Redis up.
3. A new visitor registers, sees the estimate, submits a generation, and watches it reach `succeeded`
   with its actual charge and image, without reloading.
4. The generation's webhook events appear as received and processed in production (not only through
   polling).
5. The admin can open Horizon and Pulse; a regular user gets `403`.
6. Shipping a new version is one command from the maintainer's machine; it ends by saying whether the
   deployed app is healthy, and the database and images are still there afterwards.
7. The other sites behind the edge proxy keep answering during and after the change.
8. A request with a forged `X-Forwarded-For` header is throttled by its real address.
9. No production account has a known default password.
10. The runbook lets someone who was not in this conversation install and ship the app.

## Open questions

None. Answered on 2026-11-17: SSH as `aiflow` (alias `aiflow`, already in the `docker` group), app in
`~/pixelforge-studio`; ports 80/443 belong to the edge proxy (`/home/deploy/edge`, network `edge`);
7.8 GB RAM (5.9 GB available) and 72 GB free disk; DNS already points to the VPS.
