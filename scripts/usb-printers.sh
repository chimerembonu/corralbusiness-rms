#!/usr/bin/env bash
# CorralBusiness – RMS: get USB receipt/kitchen printers ready (Ubuntu store server).
# Gives every USB printer plugged into this computer its own "raw" print queue (RMS_USB_1, RMS_USB_2…),
# so the setup wizard and Back office → Printers can find it. Safe to run again after plugging in another.
#   sudo ./scripts/usb-printers.sh
set -euo pipefail

say() { printf '\n\033[1;32m▶ %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Please run with sudo: sudo ./scripts/usb-printers.sh" >&2; exit 1; }

if ! command -v lpadmin >/dev/null 2>&1; then
    say "Installing the print system (CUPS)…"
    apt-get update -qq && apt-get install -y -qq cups >/dev/null
    systemctl enable --now cups >/dev/null 2>&1 || true
fi

found=0
added=0
while read -r uri; do
    [ -n "$uri" ] || continue
    found=$((found + 1))
    if lpstat -v 2>/dev/null | grep -qF "$uri"; then
        continue # already has a queue
    fi
    n=1
    while lpstat -p "RMS_USB_$n" >/dev/null 2>&1; do n=$((n + 1)); done
    lpadmin -p "RMS_USB_$n" -E -v "$uri" -m raw 2>/dev/null
    say "USB printer ready as RMS_USB_$n"
    added=$((added + 1))
done < <(lpinfo -v 2>/dev/null | awk '$1 == "direct" && $2 ~ /^usb:\/\// {print $2}')

if [ "$found" -eq 0 ]; then
    echo "No USB printers found. Check the cable and that the printer is switched on, then run this again."
else
    echo "$found USB printer(s) found, $added newly set up. In the setup wizard (or Back office → Printers) tap Look for printers."
fi
