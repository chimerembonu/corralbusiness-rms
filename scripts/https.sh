#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# CorralBusiness – RMS: encrypted connections (HTTPS) on the restaurant network (Complete Edition E9.1).
#
#   sudo ./scripts/https.sh on        switch HTTPS on (makes the restaurant's own certificate the first time)
#   sudo ./scripts/https.sh off       back to plain http (the certificate is kept)
#   sudo ./scripts/https.sh renew     renew the server certificate when it has under 60 days left (weekly, by cron)
#   sudo ./scripts/https.sh status    show whether HTTPS is on and when the certificate ends
#
#   Options: --ip 192.168.8.10   the server's address (default: this computer's LAN address)
#            --no-restart        only make the files; do not restart the app
#
# How it works: there is no internet certificate for a private address like 192.168.8.10, so the server makes
# its own small "certificate authority" (CA) once, and signs its own certificate with it. Each till, tablet and
# phone trusts that CA once (open http://<server>/device-setup on it). The CA can only vouch for private network
# addresses and *.local names (name constraints), so even if it were stolen it could not imitate a real website.
# Files: docker/certs/public (ca.crt, server.crt – safe to share), docker/certs/private (the server's key – root
# only) and docker/ca (the certificate authority's key – root only, never given to any container).
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/.."

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mXX  %s\033[0m\n' "$*"; exit 1; }

ACTION="${1:-status}"; shift || true
IP=""; RESTART=1
while [ $# -gt 0 ]; do
    case "$1" in
        --ip) IP="${2:-}"; shift 2 ;;
        --no-restart) RESTART=0; shift ;;
        *) die "Unknown option $1" ;;
    esac
done
if [ -n "$IP" ] && ! printf '%s' "$IP" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$'; then
    die "--ip must be an address like 192.168.8.10"
fi

command -v openssl >/dev/null || die "openssl is missing. Run: sudo apt-get install -y openssl"
[ -f .env ] || die "No .env here. Run ./install.sh first."

CERTS=docker/certs
PUB="$CERTS/public"; PRIV="$CERTS/private"
CA_DIR=docker/ca   # outside docker/certs, which nginx can read: a broken-into web container cannot make certificates
if [ -f "$PRIV/ca.key" ]; then   # made by an earlier version, inside the folder nginx can read
    mkdir -p "$CA_DIR" && chmod 700 "$CA_DIR"
    if [ -f "$CA_DIR/ca.key" ]; then rm -f "$PRIV/ca.key"; else mv "$PRIV/ca.key" "$CA_DIR/ca.key"; fi
fi
SERVER_DAYS=800   # Apple devices refuse server certificates valid for more than 825 days
CA_DAYS=3650

envget() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- || true; }
envset() {
    if grep -qE "^$1=" .env; then
        sed -i.bak "s|^$1=.*|$1=$2|" .env && rm -f .env.bak
    else
        printf '%s=%s\n' "$1" "$2" >> .env
    fi
}

lan_ip() {
    if [ -n "$IP" ]; then echo "$IP"; return; fi
    local bind; bind="$(envget RMS_BIND_IP)"
    if [ -n "$bind" ] && [ "$bind" != "0.0.0.0" ]; then echo "$bind"; return; fi
    hostname -I 2>/dev/null | awk '{print $1}'
}

make_ca() {
    [ -f "$CA_DIR/ca.key" ] && [ -f "$PUB/ca.crt" ] && return
    say "Making the restaurant's own certificate authority (once)…"
    local name; name="$(hostname -s 2>/dev/null || echo store)"
    openssl req -x509 -newkey rsa:3072 -nodes -sha256 -days "$CA_DAYS" \
        -keyout "$CA_DIR/ca.key" -out "$PUB/ca.crt" \
        -subj "/O=Corral Systems/OU=CorralBusiness RMS/CN=CorralBusiness RMS – $name" \
        -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
        -addext "keyUsage=critical,keyCertSign,cRLSign" \
        -addext "nameConstraints=critical,permitted;IP:10.0.0.0/255.0.0.0,permitted;IP:172.16.0.0/255.240.0.0,permitted;IP:192.168.0.0/255.255.0.0,permitted;IP:127.0.0.0/255.0.0.0,permitted;DNS:.local,permitted;DNS:local,permitted;DNS:localhost" \
        2>/dev/null
}

make_server_cert() {
    local ip="$1" name; name="$(hostname -s 2>/dev/null || echo store)"
    say "Making the server certificate for $ip…"
    local cfg; cfg="$(mktemp)"
    cat > "$cfg" <<CFG
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=IP:$ip,DNS:rms.local,DNS:${name}.local,DNS:localhost,IP:127.0.0.1
CFG
    openssl req -newkey rsa:2048 -nodes -sha256 -keyout "$PRIV/server.key" -out "$PRIV/server.csr" -subj "/CN=rms.local" 2>/dev/null  # the address itself is in subjectAltName; the CN must stay inside the name constraints
    openssl x509 -req -in "$PRIV/server.csr" -CA "$PUB/ca.crt" -CAkey "$CA_DIR/ca.key" -CAcreateserial \
        -out "$PUB/server.crt" -days "$SERVER_DAYS" -sha256 -extfile "$cfg" 2>/dev/null
    rm -f "$cfg" "$PRIV/server.csr" "$PUB/ca.srl" "$PRIV/ca.srl" "$CA_DIR/ca.srl"
    printf '%s\n' "$ip" > "$PRIV/server.ip"
}

lock_down() {
    chmod 755 "$CERTS" "$PUB"; chmod 644 "$PUB"/*.crt
    chmod 700 "$PRIV"; chmod 600 "$PRIV"/*
    chmod 700 "$CA_DIR"; chmod 600 "$CA_DIR"/*
}

port_suffix() {
    local port; port="$(envget RMS_HTTPS_PORT)"
    [ -z "$port" ] || [ "$port" = "443" ] && return
    echo ":$port"
}

restart() {
    [ "$RESTART" = "1" ] || return 0
    say "Restarting the app with the new settings…"
    docker compose up -d --force-recreate --no-deps web app queue scheduler reverb >/dev/null
}

case "$ACTION" in
    on)
        ip="$(lan_ip)"; [ -n "$ip" ] || die "Could not work out this computer's network address. Use --ip 192.168.x.x"
        mkdir -p "$PUB" "$PRIV" "$CA_DIR"
        make_ca
        if [ ! -f "$PUB/server.crt" ] || [ "$(cat "$PRIV/server.ip" 2>/dev/null)" != "$ip" ]; then make_server_cert "$ip"; fi
        lock_down
        envset RMS_HTTPS on
        envset APP_URL "https://$ip$(port_suffix)"
        envset SESSION_SECURE_COOKIE true
        restart
        say "HTTPS is on: https://$ip$(port_suffix)"
        echo "    On each till, tablet and phone, open http://$ip$( [ -n "$(envget RMS_HTTP_PORT)" ] && [ "$(envget RMS_HTTP_PORT)" != "80" ] && echo ":$(envget RMS_HTTP_PORT)")/device-setup once"
        echo "    and follow the steps: it installs the restaurant's certificate so the padlock shows."
        echo "    Certificate fingerprint (to compare on the device):"
        echo "    $(openssl x509 -in "$PUB/ca.crt" -noout -fingerprint -sha256 | cut -d= -f2)"
        ;;
    off)
        envset RMS_HTTPS off
        envset APP_URL "http://$(lan_ip)$( [ -n "$(envget RMS_HTTP_PORT)" ] && [ "$(envget RMS_HTTP_PORT)" != "80" ] && echo ":$(envget RMS_HTTP_PORT)")"
        envset SESSION_SECURE_COOKIE false
        restart
        say "HTTPS is off. The certificate is kept, so switching it on again needs no new setup on the devices."
        ;;
    renew)
        [ "$(envget RMS_HTTPS)" = "on" ] || { echo "HTTPS is off: nothing to renew."; exit 0; }
        ip="$(cat "$PRIV/server.ip" 2>/dev/null || lan_ip)"
        if openssl x509 -in "$PUB/server.crt" -noout -checkend $((60 * 86400)) >/dev/null 2>&1; then
            echo "The server certificate is good for more than 60 days: nothing to do."
            exit 0
        fi
        make_server_cert "$ip"; lock_down
        [ "$RESTART" = "1" ] && docker compose restart web >/dev/null
        say "Server certificate renewed (the devices need nothing new)."
        ;;
    status)
        echo "HTTPS: $(envget RMS_HTTPS || echo off)"
        if [ -f "$PUB/server.crt" ]; then
            echo "Server certificate for: $(cat "$PRIV/server.ip" 2>/dev/null || echo '?')"
            echo "Ends: $(openssl x509 -in "$PUB/server.crt" -noout -enddate | cut -d= -f2)"
            echo "Restaurant certificate fingerprint: $(openssl x509 -in "$PUB/ca.crt" -noout -fingerprint -sha256 | cut -d= -f2)"
        else
            echo "No certificate made yet (run: sudo ./scripts/https.sh on)."
        fi
        ;;
    *) die "Use: on | off | renew | status" ;;
esac
