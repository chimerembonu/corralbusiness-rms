#!/usr/bin/env bash
# Hotfix loop on the store server: pull the latest code, rebuild, restart. Data and .env are kept.
#   cd /opt/corralbusiness-rms && sudo ./scripts/update.sh
set -euo pipefail
cd "$(dirname "$0")/.."
echo "==> Backing up the database first…"
docker compose exec -T app php artisan rms:backup || echo "    (backup command not available yet – continuing)"
echo "==> Getting the latest code…"
git pull --ff-only
echo "==> Rebuilding and restarting…"
docker compose build --pull
docker compose up -d --remove-orphans
docker compose exec -T app php artisan about --only=environment
echo "==> Updated."
