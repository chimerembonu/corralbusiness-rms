#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# CorralBusiness – RMS: one-line installer for the store server (Ubuntu 24.04).
#
#   From a copy of the code (USB stick or git clone):  sudo ./install.sh
#   The repository is private, so clone it first with a GitHub login:
#     gh auth login && gh repo clone chimerembonu/corralbusiness-rms /opt/corralbusiness-rms
#
# What it does, in order:
#   1. Installs Docker (if missing).
#   2. Puts the code in /opt/corralbusiness-rms (git clone or the folder you ran it from).
#   3. Creates .env with generated keys and passwords, bound to this computer's LAN address.
#   4. Builds and starts everything (web, app, database, realtime, queue, scheduler).
#   5. Shows the address and setup code of the browser setup wizard (/setup), unless a restaurant already exists.
# Safe to run again: it keeps the existing .env and data.
#
# Encrypted connections (HTTPS, Complete Edition E9.1) are off unless asked for:  sudo ./install.sh --https
# (or later: sudo ./scripts/https.sh on). Each device then trusts the restaurant's certificate once at /device-setup.
# ---------------------------------------------------------------------------
set -euo pipefail

REPO_URL="${RMS_REPO_URL:-https://github.com/chimerembonu/corralbusiness-rms.git}"
TARGET="${RMS_DIR:-/opt/corralbusiness-rms}"
BRANCH="${RMS_BRANCH:-main}"
WANT_HTTPS="${RMS_HTTPS:-off}"
for arg in "$@"; do
    case "$arg" in
        --https) WANT_HTTPS=on ;;
    esac
done

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33m!!  %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mXX  %s\033[0m\n' "$*"; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run with sudo: sudo ./install.sh"
command -v apt-get >/dev/null || die "This installer is for Ubuntu/Debian. On a Mac, install Docker Desktop and run: docker compose up -d"

# 1. Docker --------------------------------------------------------------------
if ! command -v docker >/dev/null; then
    say "Installing Docker…"
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg git >/dev/null
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin >/dev/null
    systemctl enable --now docker
fi
docker compose version >/dev/null 2>&1 || die "Docker Compose plugin missing. Run: apt-get install docker-compose-plugin"

# 2. Code ----------------------------------------------------------------------
if [ -f "$(pwd)/docker-compose.yml" ] && [ -f "$(pwd)/artisan" ]; then
    SRC="$(pwd)"
    if [ "$SRC" != "$TARGET" ]; then
        say "Copying the code to $TARGET…"
        mkdir -p "$TARGET"
        cp -a "$SRC"/. "$TARGET"/
    fi
elif [ -d "$TARGET/.git" ]; then
    say "Updating the code in $TARGET…"
    git -C "$TARGET" pull --ff-only
else
    say "Downloading the code to $TARGET…"
    command -v git >/dev/null || apt-get install -y -qq git >/dev/null
    git clone --branch "$BRANCH" "$REPO_URL" "$TARGET"
fi
cd "$TARGET"

# 3. Settings ------------------------------------------------------------------
LAN_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
[ -n "$LAN_IP" ] || die "Could not work out this computer's network address. Connect it to the restaurant network and run the installer again."
if [ ! -f .env ]; then
    say "Creating .env (keys and passwords are generated once and kept)…"
    cp .env.docker.example .env
    rand() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$1"; }
    sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
    sed -i "s|^APP_URL=.*|APP_URL=http://$LAN_IP|" .env
    sed -i "s|^RMS_BIND_IP=.*|RMS_BIND_IP=$LAN_IP|" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(rand 24)|" .env
    sed -i "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$(rand 24)|" .env
    sed -i "s|^REVERB_APP_ID=.*|REVERB_APP_ID=$(rand 8)|" .env
    sed -i "s|^REVERB_APP_KEY=.*|REVERB_APP_KEY=$(rand 20)|" .env
    sed -i "s|^REVERB_APP_SECRET=.*|REVERB_APP_SECRET=$(rand 32)|" .env
    sed -i "s|^RMS_BACKUP_PASSWORD=.*|RMS_BACKUP_PASSWORD=$(rand 24)|" .env
    sed -i "s|^RMS_SETUP_CODE=.*|RMS_SETUP_CODE=$(tr -dc '0-9' </dev/urandom | head -c 6)|" .env
    chmod 600 .env
else
    say "Keeping the existing .env."
fi
# A setup code for the browser wizard, also on an older .env that has none (the wizard refuses to open without one).
if ! grep -q '^RMS_SETUP_CODE=[0-9]' .env; then
    CODE="$(tr -dc '0-9' </dev/urandom | head -c 6)"
    if grep -q '^RMS_SETUP_CODE=' .env; then
        sed -i "s|^RMS_SETUP_CODE=.*|RMS_SETUP_CODE=$CODE|" .env
    else
        printf '\n# (set by install) the code the browser setup wizard asks for\nRMS_SETUP_CODE=%s\n' "$CODE" >> .env
    fi
fi
# The restaurant network the app searches for printers on (the app cannot see it from inside Docker).
LAN_NET="$(ip -4 -o addr show 2>/dev/null | awk -v ip="$LAN_IP" '{split($4, a, "/"); if (a[1] == ip) print $4}' | head -1)"
if [ -n "$LAN_NET" ]; then
    if grep -q '^RMS_PRINTER_SUBNET=' .env; then
        sed -i "s|^RMS_PRINTER_SUBNET=$|RMS_PRINTER_SUBNET=$LAN_NET|" .env
    else
        printf '\n# (set by install) the restaurant network printers are searched on\nRMS_PRINTER_SUBNET=%s\n' "$LAN_NET" >> .env
    fi
fi

# USB printers: give each one a print queue, then share the computer's print system (CUPS) with the app.
./scripts/usb-printers.sh || say "USB printers could not be prepared; network printers still work. Run sudo ./scripts/usb-printers.sh later."
if [ -S /run/cups/cups.sock ]; then
    rm -rf docker/cups && ln -sfn /run/cups docker/cups
fi

# HTTPS: make the restaurant's certificate and switch it on (only when asked for); renew it weekly.
if [ "$WANT_HTTPS" = "on" ]; then
    ./scripts/https.sh on --no-restart
fi
if grep -q '^RMS_HTTPS=on' .env; then
    printf '# CorralBusiness – RMS: renew the HTTPS server certificate when it has under 60 days left\n17 4 * * 1 root cd %s && ./scripts/https.sh renew >/dev/null 2>&1\n' "$TARGET" > /etc/cron.d/corralbusiness-rms-https
fi
SCHEME=http; grep -q '^RMS_HTTPS=on' .env && SCHEME=https

# 4. Start ---------------------------------------------------------------------
say "Building and starting CorralBusiness – RMS (first time takes a few minutes)…"
docker compose build --pull
docker compose up -d --remove-orphans
say "Waiting for the app to come up…"
for i in $(seq 1 60); do
    if docker compose exec -T app php artisan about --only=environment >/dev/null 2>&1; then break; fi
    sleep 3
done

# 5. Restaurant + owner --------------------------------------------------------
if docker compose exec -T app php artisan rms:setup --check >/dev/null 2>&1; then
    say "The restaurant is already set up."
else
    SETUP_CODE="$(grep '^RMS_SETUP_CODE=' .env | cut -d= -f2-)"
    say "Finish in a browser: open http://$LAN_IP/setup on any computer, tablet or phone on the restaurant network."
    echo "    Setup code: ${SETUP_CODE:-(none needed)}"
    echo "    The setup screens ask for the restaurant, owner login, taxes, receipt, tills, printers, menu and staff."
    echo "    Switch the printers on first: the wizard finds them and prints a test page."
    echo "    (Prefer the command line? Run: docker compose exec app php artisan rms:setup)"
fi

say "Done. Open $SCHEME://$LAN_IP on any till, tablet or phone on the restaurant Wi-Fi."
echo "    Back office:  $SCHEME://$LAN_IP/admin"
echo "    Till:         $SCHEME://$LAN_IP/pos"
echo "    Kitchen:      $SCHEME://$LAN_IP/kitchen"
echo "    Clock in:     $SCHEME://$LAN_IP/clock"
if [ "$SCHEME" = "https" ]; then
    echo "    Secure connection: on each device, open http://$LAN_IP/device-setup once (it installs the restaurant's certificate)."
fi
echo "Give this computer a fixed address on the router (see docs/STORE-SERVER.md) so the links never change."
echo
echo "IMPORTANT – your backup password (backups are locked with it):"
echo "    $(grep '^RMS_BACKUP_PASSWORD=' .env | cut -d= -f2-)"
echo "Write it down and keep it somewhere safe, away from this computer. You need it to restore a backup"
echo "on a replacement computer. Show it again any time with: docker compose exec app php artisan rms:backup --show-password"
