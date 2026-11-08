#!/usr/bin/env bash
# Hotfix loop on the store server: pull the latest code, rebuild, restart. Data and .env are kept.
#   cd /opt/corralbusiness-rms && sudo ./scripts/update.sh
set -euo pipefail
cd "$(dirname "$0")/.."
echo "==> Backing up the database first…"
# Never update without a fresh backup (compliance assessment). For an emergency only – e.g. the app will not
# start, so no backup can be taken – run:  SKIP_BACKUP=1 sudo -E ./scripts/update.sh
if [ "${SKIP_BACKUP:-0}" = "1" ]; then
    echo "    !! SKIP_BACKUP=1 – updating WITHOUT a backup. Only do this if the app cannot start."
elif ! docker compose exec -T app php artisan rms:backup --note="before update"; then
    echo
    echo "    The backup failed, so NOTHING has been updated and the restaurant keeps running as before."
    echo "    Check the server has free disk space and the app is running (docker compose ps), then try again."
    echo "    If it still fails, contact Corral Systems support before updating."
    exit 1
fi
echo "==> Getting the latest code…"
git pull --ff-only
# HTTPS (E9.1): renew the server certificate now if it is close to its end (nothing happens when HTTPS is off).
./scripts/https.sh renew --no-restart || echo "    (the HTTPS certificate could not be checked; the app keeps running)"
echo "==> Rebuilding and restarting…"
docker compose build --pull
docker compose up -d --remove-orphans
docker compose exec -T app php artisan about --only=environment
echo "==> Updated."
