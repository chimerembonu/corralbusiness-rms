#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# CorralBusiness – RMS: one-line installer for the store server (Ubuntu 24.04).
#
#   curl -fsSL https://raw.githubusercontent.com/<owner>/corralbusiness-rms/main/install.sh | sudo bash
#   or, from a copy of the code:  sudo ./install.sh
#
# What it does, in order:
#   1. Installs Docker (if missing).
#   2. Puts the code in /opt/corralbusiness-rms (git clone or the folder you ran it from).
#   3. Creates .env with generated keys and passwords, bound to this computer's LAN address.
#   4. Builds and starts everything (web, app, database, realtime, queue, scheduler).
#   5. Creates the restaurant and the owner login (rms:setup), unless the database already has one.
# Safe to run again: it keeps the existing .env and data.
# ---------------------------------------------------------------------------
set -euo pipefail

REPO_URL="${RMS_REPO_URL:-https://github.com/corralsystems/corralbusiness-rms.git}"
TARGET="${RMS_DIR:-/opt/corralbusiness-rms}"
BRANCH="${RMS_BRANCH:-main}"

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
[ -n "$LAN_IP" ] || LAN_IP="0.0.0.0"
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
    chmod 600 .env
else
    say "Keeping the existing .env."
fi

# USB printers: share the computer's print system (CUPS) with the app.
if [ -S /run/cups/cups.sock ]; then
    rm -rf docker/cups && ln -sfn /run/cups docker/cups
fi

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
    say "Let's set up the restaurant and the owner login."
    docker compose exec app php artisan rms:setup
fi

say "Done. Open http://$LAN_IP on any till, tablet or phone on the restaurant Wi-Fi."
echo "    Back office:  http://$LAN_IP/admin"
echo "    Till:         http://$LAN_IP/pos"
echo "    Kitchen:      http://$LAN_IP/kitchen"
echo "    Clock in:     http://$LAN_IP/clock"
echo "Give this computer a fixed address on the router (see docs/STORE-SERVER.md) so the links never change."
