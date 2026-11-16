# 0016. Docker image with FrankenPHP (classic mode)

Status: accepted (2026-11-16)

## Context

The project must start with `docker compose up` and later run on a VPS from the same image.

## Decision

- One multi-stage `Dockerfile`: Composer dependencies, front-end build (Node, with PHP available
  because Wayfinder generates route helpers during the build), and a slim final stage on
  `dunglas/frankenphp` with OPcache, a non-root user and a healthcheck. A `dev` target adds Composer
  and Node for local development.
- FrankenPHP in **classic mode** (no Octane worker mode): no state leaks between requests and nothing
  new to explain. Worker mode is a possible later optimisation.
- The same image runs the web server, Horizon and the scheduler; only the command differs.
- The entrypoint waits for the database, runs migrations (web role only) and caches config, routes,
  views and events in production.
- `compose.yaml` for development (bind mount, Vite, optional Cloudflare tunnel profile);
  `compose.prod.yaml` for production (no bind mounts, restart policies, resource limits, named
  volumes).

## Consequences

- Development and production share one image definition.
- Bind mounts on Windows and macOS are slower than native file access; acceptable for development.
