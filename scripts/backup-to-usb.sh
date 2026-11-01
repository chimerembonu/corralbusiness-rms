#!/usr/bin/env bash
# Take a backup now and copy it to the first USB stick plugged into the server.
#   cd /opt/corralbusiness-rms && sudo ./scripts/backup-to-usb.sh
set -euo pipefail
cd "$(dirname "$0")/.."
STICK="$(find /media -mindepth 2 -maxdepth 2 -type d 2>/dev/null | head -1 || true)"
if [ -z "$STICK" ]; then
    echo "No USB stick found under /media. Plug one in, wait 5 seconds and try again."
    exit 1
fi
echo "==> Backing up and copying to $STICK …"
docker compose exec -T app php artisan rms:backup --copy-to="$STICK" --note=usb
sync
echo "==> Done. You can remove the stick."
