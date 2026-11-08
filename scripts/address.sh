#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# CorralBusiness – RMS: follow the server's network address (Ubuntu store server).
#
# The app listens only on the restaurant network address (RMS_BIND_IP in .env). If the router gives the server
# a new address (after a power cut, a new router…), the app could not start on the old one. install.sh runs this
# every minute and after every start; when the address has changed it moves the app to the new one, renews the
# HTTPS certificate for it if HTTPS is on, and restarts. Otherwise it does nothing.
#
#   sudo ./scripts/address.sh                 check now (what the schedule does)
#   sudo ./scripts/address.sh --banner-only   only refresh the message on the server's own screen
#
# Tills keep the address they were given, so reserving the address on the router (docs/STORE-SERVER.md §3) is
# still best; this makes sure the app itself never stays down because of a new address.
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] || exit 0
[ "$(uname -s)" = Linux ] || { echo "Only needed on the Ubuntu store server."; exit 0; }

envget() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- || true; }
envset() {
    if grep -qE "^$1=" .env; then
        sed -i.bak "s|^$1=.*|$1=$2|" .env && rm -f .env.bak
    else
        printf '%s=%s\n' "$1" "$2" >> .env
    fi
}
current_ip() {   # RMS_DETECTED_IP lets the tests pretend
    if [ -n "${RMS_DETECTED_IP:-}" ]; then echo "$RMS_DETECTED_IP"; return; fi
    { ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") { print $(i + 1); exit }}'; hostname -I 2>/dev/null | awk '{print $1}'; } \
        | grep -E '^[0-9]+(\.[0-9]+){3}$' | grep -v '^127\.' | head -1 || true
}
all_ips() { [ -n "${RMS_DETECTED_IP:-}" ] && echo "$RMS_DETECTED_IP" || hostname -I 2>/dev/null | tr ' ' '\n'; }

banner() {   # the message under the login prompt on the server's own screen
    local ip="$1" scheme=http port suffix=""
    [ "$(envget RMS_HTTPS)" = on ] && scheme=https
    if [ "$scheme" = https ]; then port="$(envget RMS_HTTPS_PORT)"; [ -z "$port" ] || [ "$port" = 443 ] || suffix=":$port"
    else port="$(envget RMS_HTTP_PORT)"; [ -z "$port" ] || [ "$port" = 80 ] || suffix=":$port"; fi
    [ -d /etc/issue.d ] || return 0
    cat > /etc/issue.d/corralbusiness-rms.issue <<ISSUE

  CorralBusiness – RMS is on this computer.
  On the tills, tablets and phones, open:  $scheme://$ip$suffix
  First time? Open $scheme://$ip$suffix/setup  (setup code: $(envget RMS_SETUP_CODE))

ISSUE
}

if [ "${1:-}" = "--banner-only" ]; then
    ip="$(envget RMS_BIND_IP)"; [ -n "$ip" ] && banner "$ip"
    exit 0
fi

# One at a time: the schedule runs every minute and a restart can take longer.
exec 9>/tmp/corralbusiness-rms-address.lock
flock -n 9 || exit 0

new="$(current_ip)"
old="$(envget RMS_BIND_IP)"
[ -n "$new" ] || exit 0                                    # no network yet: try again next minute
[ "$new" = "$old" ] && exit 0                              # nothing changed
ips="$(all_ips)"                                           # (kept in a variable: "grep -q" in a pipe can fail under pipefail)
[ -n "$old" ] && grep -qxF "$old" <<<"$ips" && exit 0      # the old address is still on this computer (two networks)

logger -t corralbusiness-rms "Network address changed from ${old:-none} to $new: moving the app" 2>/dev/null || true
echo "==> The network address changed from ${old:-none} to $new: moving the app to it…"
envset RMS_BIND_IP "$new"
if [ "$(envget RMS_HTTPS)" = on ]; then
    ./scripts/https.sh on --ip "$new" --no-restart >/dev/null   # a certificate for the new address; devices need nothing new
else
    port="$(envget RMS_HTTP_PORT)"; suffix=""; [ -z "$port" ] || [ "$port" = 80 ] || suffix=":$port"
    envset APP_URL "http://$new$suffix"
fi
current_net="$(ip -4 -o addr show 2>/dev/null | awk -v ip="$new" '{split($4, a, "/"); if (a[1] == ip) print $4}' | head -1 || true)"
[ -n "$current_net" ] && envset RMS_PRINTER_SUBNET "$current_net"
banner "$new"
[ "${RMS_NO_RESTART:-0}" = 1 ] || docker compose up -d >/dev/null 2>&1
echo "==> The app now answers on $new."
