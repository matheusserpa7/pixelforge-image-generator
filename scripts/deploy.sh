#!/bin/sh
# Ship the pushed `main` to the server and check that the deployed app is healthy.
# Usage: DEPLOY_HOST=user@host sh scripts/deploy.sh   (or: make deploy DEPLOY_HOST=user@host)
set -eu

DEPLOY_PATH="${DEPLOY_PATH:-/opt/pixelforge-studio}"
DEPLOY_URL="${DEPLOY_URL:-https://pixelforge.matheusserpa.online}"

if [ -z "${DEPLOY_HOST:-}" ]; then
    echo "Usage: DEPLOY_HOST=user@host sh scripts/deploy.sh" >&2
    echo "Optional: DEPLOY_PATH (default /opt/pixelforge-studio), DEPLOY_URL (default $DEPLOY_URL)." >&2
    exit 1
fi

git fetch origin main
if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
    echo "Warning: local HEAD differs from origin/main. Only pushed commits are deployed." >&2
fi

ssh "$DEPLOY_HOST" "set -e
cd '$DEPLOY_PATH'
git pull --ff-only origin main
docker compose -f compose.prod.yaml up -d --build --wait --remove-orphans
docker image prune -f
echo \"Deployed \$(git rev-parse --short HEAD)\""

if body=$(curl -fsS --max-time 10 "$DEPLOY_URL/health"); then
    echo "$body"
    echo "Healthy"
else
    echo "Health check failed" >&2
    exit 1
fi
