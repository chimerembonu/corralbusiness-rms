#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# CorralBusiness – RMS: one-command installer for the store server.
#
#   Ubuntu 24.04 (recommended), from nothing:
#     curl -fsSL https://raw.githubusercontent.com/chimerembonu/corralbusiness-rms/main/install.sh | sudo bash
#   Mac with Docker Desktop running (no sudo):
#     curl -fsSL https://raw.githubusercontent.com/chimerembonu/corralbusiness-rms/main/install.sh | bash
#   From a copy of the code (USB stick or git clone): open a terminal in that folder and run ./install.sh
#   (with sudo on Ubuntu).
#
# Options:  --https          encrypted connections on the restaurant network (Complete Edition E9.1)
#           --branch NAME    which version to download (default main; the Complete Edition: complete-edition)
#           --port N         the web port (default 80, or 8080 when something else already uses 80)
#           --dir PATH       where to install (default /opt/corralbusiness-rms, on a Mac ~/CorralBusiness-RMS)
#
# What it does, in order:
#   1. Installs Docker on Ubuntu (if missing). On a Mac, Docker Desktop must be installed and running.
#   2. Puts the code in the install folder (download, or the folder you ran it from).
#   3. Creates .env with generated keys and passwords, bound to this computer's network address. Nothing to fill in.
#   4. Builds and starts everything (web, app, database, realtime, queue, scheduler).
#   5. Ubuntu: keeps the app reachable if the router later gives this computer a new address, and shows the
#      address and setup code on the server's own screen.
#   6. Shows the address and setup code of the browser setup wizard (/setup), unless a restaurant already exists.
# Safe to run again: it keeps the existing .env and data.
# ---------------------------------------------------------------------------
set -euo pipefail

REPO_URL="${RMS_REPO_URL:-https://github.com/chimerembonu/corralbusiness-rms.git}"
BRANCH="${RMS_BRANCH:-main}"
WANT_HTTPS="${RMS_HTTPS:-off}"
WANT_PORT="${RMS_HTTP_PORT:-}"
TARGET="${RMS_DIR:-}"
while [ $# -gt 0 ]; do
    case "$1" in
        --https) WANT_HTTPS=on; shift ;;
        --branch) BRANCH="${2:?--branch needs a name}"; shift 2 ;;
        --port) WANT_PORT="${2:?--port needs a number}"; shift 2 ;;
        --dir) TARGET="${2:?--dir needs a folder}"; shift 2 ;;
        *) shift ;;
    esac
done

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33m!!  %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mXX  %s\033[0m\n' "$*"; exit 1; }

case "$(uname -s)" in
    Linux) PLATFORM=linux ;;
    Darwin) PLATFORM=mac ;;
    *) die "This installer is for Ubuntu or a Mac. On Windows, see docs/STORE-SERVER.md section 2b." ;;
esac

if [ "$PLATFORM" = linux ]; then
    [ "$(id -u)" -eq 0 ] || die "Run with sudo: sudo ./install.sh"
    command -v apt-get >/dev/null || die "This installer is for Ubuntu (or Debian). See docs/STORE-SERVER.md for other systems."
    TARGET="${TARGET:-/opt/corralbusiness-rms}"
else
    [ "$(id -u)" -ne 0 ] || die "On a Mac, run it without sudo: ./install.sh"
    TARGET="${TARGET:-$HOME/CorralBusiness-RMS}"
    # Docker Desktop puts its commands here; a fresh Terminal may not know them yet.
    export PATH="$PATH:/usr/local/bin:$HOME/.docker/bin:/Applications/Docker.app/Contents/Resources/bin"
fi
[ -n "$WANT_PORT" ] && ! printf '%s' "$WANT_PORT" | grep -Eq '^[0-9]{2,5}$' && die "--port must be a number, like 8080"

# Random text for keys and passwords. (openssl output is finite, so no "broken pipe" under pipefail.)
rand()   { openssl rand -base64 64 | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c1-"$1"; }
digits() { printf '%06d' $(( $(od -An -N4 -tu4 /dev/urandom | tr -d ' ') % 1000000 )); }
envget() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- || true; }
envset() {   # works with both GNU (Ubuntu) and BSD (Mac) sed
    if grep -qE "^$1=" .env; then
        sed -i.bak "s|^$1=.*|$1=$2|" .env && rm -f .env.bak
    else
        printf '%s=%s\n' "$1" "$2" >> .env
    fi
}
# This computer's address on the restaurant network (the one its default route uses).
lan_ip() {
    if [ "$PLATFORM" = linux ]; then
        ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") { print $(i + 1); exit }}' \
            || true
        hostname -I 2>/dev/null | awk '{print $1}'
    else
        local iface; iface="$(route -n get default 2>/dev/null | awk '/interface:/ {print $2}')"
        [ -n "$iface" ] && ipconfig getifaddr "$iface" 2>/dev/null
        ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || true
    fi
}
port_busy() {   # something already answers on this port (another web server, say)
    local port="$1" host
    for host in 127.0.0.1 "$LAN_IP"; do
        (exec 3<>"/dev/tcp/$host/$port") 2>/dev/null && return 0
    done
    return 1
}

# 1. Docker --------------------------------------------------------------------
if [ "$PLATFORM" = linux ]; then
    if ! command -v docker >/dev/null; then
        say "Installing Docker…"
        apt-get update -qq
        apt-get install -y -qq ca-certificates curl gnupg git openssl >/dev/null
        install -m 0755 -d /etc/apt/keyrings
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor --yes -o /etc/apt/keyrings/docker.gpg
        chmod a+r /etc/apt/keyrings/docker.gpg
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list
        apt-get update -qq
        apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin >/dev/null
    fi
    systemctl enable --now docker >/dev/null 2>&1 || true   # also starts by itself after a power cut
    command -v git >/dev/null || apt-get install -y -qq git >/dev/null
    command -v openssl >/dev/null || apt-get install -y -qq openssl >/dev/null
else
    command -v docker >/dev/null || die "Docker Desktop is not installed. Get it from https://www.docker.com/products/docker-desktop/, open it once, then run this again."
    if ! docker info >/dev/null 2>&1; then
        say "Starting Docker Desktop…"
        open -a Docker 2>/dev/null || true
        for _ in $(seq 1 60); do docker info >/dev/null 2>&1 && break; sleep 2; done
        docker info >/dev/null 2>&1 || die "Docker Desktop did not start. Open it from Applications, wait for the whale icon to settle, then run this again."
    fi
fi
docker compose version >/dev/null 2>&1 || die "Docker Compose is missing. Ubuntu: sudo apt-get install docker-compose-plugin. Mac: update Docker Desktop."

# 2. Code ----------------------------------------------------------------------
HERE="$(pwd)"
if [ -f "$HERE/docker-compose.yml" ] && [ -f "$HERE/artisan" ]; then
    if [ "$HERE" != "$TARGET" ]; then
        say "Copying the code to $TARGET…"
        mkdir -p "$TARGET"
        cp -a "$HERE"/. "$TARGET"/
    fi
elif [ -d "$TARGET/.git" ]; then
    say "Updating the code in $TARGET…"
    git -C "$TARGET" pull --ff-only
else
    say "Downloading CorralBusiness – RMS ($BRANCH) to $TARGET…"
    command -v git >/dev/null || die "git is missing. On a Mac, run: xcode-select --install"
    git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$TARGET"
fi
cd "$TARGET"

# 3. Settings ------------------------------------------------------------------
LAN_IP="$(lan_ip | grep -E '^[0-9]+(\.[0-9]+){3}$' | grep -v '^127\.' | head -1 || true)"
[ -n "$LAN_IP" ] || die "Could not work out this computer's network address. Connect it to the restaurant network (a cable to the router is best) and run the installer again."
if [ ! -f .env ]; then
    say "Creating the settings file (.env): keys and passwords are generated once and kept…"
    cp .env.docker.example .env
    PORT="${WANT_PORT:-80}"
    if [ -z "$WANT_PORT" ] && port_busy 80; then
        PORT=8080
        while port_busy "$PORT"; do PORT=$((PORT + 1)); done
        warn "Port 80 is already used by another program on this computer, so CorralBusiness – RMS uses port $PORT."
    fi
    SUFFIX=""; [ "$PORT" = "80" ] || SUFFIX=":$PORT"
    envset APP_KEY "base64:$(openssl rand -base64 32)"
    envset APP_URL "http://$LAN_IP$SUFFIX"
    envset RMS_BIND_IP "$LAN_IP"
    envset RMS_HTTP_PORT "$PORT"
    envset DB_PASSWORD "$(rand 24)"
    envset DB_ROOT_PASSWORD "$(rand 24)"
    envset REVERB_APP_ID "$(rand 8)"
    envset REVERB_APP_KEY "$(rand 20)"
    envset REVERB_APP_SECRET "$(rand 32)"
    envset RMS_BACKUP_PASSWORD "$(rand 24)"
    envset RMS_SETUP_CODE "$(digits)"
    if [ "$PLATFORM" = mac ]; then
        # A Mac has no /media folder for USB sticks: backup copies go to a folder here instead.
        mkdir -p "$TARGET/usb-copies"
        envset RMS_USB_DIR "$TARGET/usb-copies"
    fi
    chmod 600 .env
else
    say "Keeping the existing settings (.env)."
fi
# A setup code for the browser wizard, also on an older .env that has none (the wizard refuses to open without one).
grep -qE '^RMS_SETUP_CODE=[0-9]' .env || envset RMS_SETUP_CODE "$(digits)"
# The restaurant network the app searches for printers on (the app cannot see it from inside Docker).
if [ -z "$(envget RMS_PRINTER_SUBNET)" ]; then
    LAN_NET=""
    if [ "$PLATFORM" = linux ]; then
        LAN_NET="$(ip -4 -o addr show 2>/dev/null | awk -v ip="$LAN_IP" '{split($4, a, "/"); if (a[1] == ip) print $4}' | head -1)"
    fi
    [ -n "$LAN_NET" ] || LAN_NET="${LAN_IP%.*}.0/24"
    envset RMS_PRINTER_SUBNET "$LAN_NET"
fi
PORT="$(envget RMS_HTTP_PORT)"; PORT="${PORT:-80}"
SUFFIX=""; [ "$PORT" = "80" ] || SUFFIX=":$PORT"

if [ "$PLATFORM" = linux ]; then
    # USB printers: give each one a print queue, then share the computer's print system (CUPS) with the app.
    ./scripts/usb-printers.sh || warn "USB printers could not be prepared; network printers still work. Run sudo ./scripts/usb-printers.sh later."
    if [ -S /run/cups/cups.sock ]; then
        rm -rf docker/cups && ln -sfn /run/cups docker/cups
    fi
fi

# HTTPS: make the restaurant's certificate and switch it on (only when asked for); renew it weekly.
if [ "$WANT_HTTPS" = "on" ]; then
    ./scripts/https.sh on --no-restart
fi
if [ "$PLATFORM" = linux ] && [ "$(envget RMS_HTTPS)" = "on" ]; then
    printf '# CorralBusiness – RMS: renew the HTTPS server certificate when it has under 60 days left\n17 4 * * 1 root cd %s && ./scripts/https.sh renew >/dev/null 2>&1\n' "$TARGET" > /etc/cron.d/corralbusiness-rms-https
fi
SCHEME=http; [ "$(envget RMS_HTTPS)" = "on" ] && SCHEME=https
[ "$SCHEME" = https ] && { HP="$(envget RMS_HTTPS_PORT)"; SUFFIX=""; [ -z "$HP" ] || [ "$HP" = "443" ] || SUFFIX=":$HP"; }

# 4. Start ---------------------------------------------------------------------
say "Building and starting CorralBusiness – RMS (the first time takes 5–15 minutes)…"
docker compose build --pull
docker compose up -d --remove-orphans
say "Waiting for the app to come up…"
for _ in $(seq 1 100); do
    if docker compose exec -T app php artisan about --only=environment >/dev/null 2>&1; then break; fi
    sleep 3
done

# 5. Ubuntu: follow address changes, and show the links on the server's own screen ----------------------------
if [ "$PLATFORM" = linux ]; then
    printf '# CorralBusiness – RMS: if the router gives this computer a new address, move the app to it (scripts/address.sh)\n* * * * * root cd %s && ./scripts/address.sh >/dev/null 2>&1\n@reboot root sleep 20 && cd %s && ./scripts/address.sh >/dev/null 2>&1\n' "$TARGET" "$TARGET" > /etc/cron.d/corralbusiness-rms-address
    ./scripts/address.sh --banner-only || true
fi

# 6. Restaurant + owner --------------------------------------------------------
SETUP_NEEDED=1
docker compose exec -T app php artisan rms:setup --check >/dev/null 2>&1 && SETUP_NEEDED=0
ADDRESS="$SCHEME://$LAN_IP$SUFFIX"

say "CorralBusiness – RMS is running."
if [ "$SETUP_NEEDED" = 1 ]; then
    echo
    echo "    NEXT: open this address on any computer, tablet or phone on the restaurant network:"
    echo
    echo "        $ADDRESS/setup"
    echo
    echo "    Setup code: $(envget RMS_SETUP_CODE)"
    echo
    echo "    The setup screens ask for the restaurant, owner login, taxes, receipt, tills, printers, menu and staff."
    echo "    Switch the printers on first: the setup finds them and prints a test page."
    [ "$PLATFORM" = mac ] && open "$ADDRESS/setup" 2>/dev/null || true
else
    echo "    The restaurant is already set up."
fi
echo
echo "    Back office:  $ADDRESS/admin"
echo "    Till:         $ADDRESS/pos"
echo "    Kitchen:      $ADDRESS/kitchen"
echo "    Clock in:     $ADDRESS/clock"
if [ "$SCHEME" = "https" ]; then
    HTTP_SUFFIX=""; [ "$PORT" = "80" ] || HTTP_SUFFIX=":$PORT"
    echo "    Secure connection: on each device, open http://$LAN_IP$HTTP_SUFFIX/device-setup once (it installs the restaurant's certificate)."
fi
if [ "$PLATFORM" = linux ]; then
    echo "    Tip: reserve this address for the server on the router (docs/STORE-SERVER.md section 3), so the links never change."
else
    echo "    Tip: in Docker Desktop → Settings → General, tick \"Start Docker Desktop when you sign in\", so the app comes back after a restart."
fi
echo
echo "IMPORTANT – your backup password (backups are locked with it):"
echo "    $(envget RMS_BACKUP_PASSWORD)"
echo "Write it down and keep it somewhere safe, away from this computer. You need it to restore a backup"
echo "on a replacement computer. The setup screens show it again. Later: ./scripts/rms rms:backup --show-password"
