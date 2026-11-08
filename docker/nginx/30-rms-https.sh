#!/bin/sh
# Runs when the web container starts (nginx image: /docker-entrypoint.d). Chooses plain http or HTTPS.
set -e
if [ "${RMS_HTTPS:-off}" = "on" ]; then
    if [ -f /etc/nginx/certs/public/server.crt ] && [ -f /etc/nginx/certs/private/server.key ]; then
        suffix=""
        if [ -n "${RMS_HTTPS_PORT:-}" ] && [ "${RMS_HTTPS_PORT}" != "443" ]; then suffix=":${RMS_HTTPS_PORT}"; fi
        sed "s|\${RMS_HTTPS_PORT_SUFFIX}|${suffix}|g" /etc/nginx/rms/https.conf > /etc/nginx/conf.d/default.conf
        echo "rms: HTTPS on"
    else
        echo "rms: RMS_HTTPS=on but no certificate in docker/certs (run: sudo ./scripts/https.sh on). Staying on plain http." >&2
    fi
else
    echo "rms: plain http (HTTPS is off)"
fi
