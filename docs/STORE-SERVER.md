# The store server (restaurant computer)

CorralBusiness – RMS runs on one small computer inside the restaurant. Tills, kitchen screens, tablets and phones open it over the restaurant Wi-Fi. It keeps working when the internet is down.

## 1. What to buy

- A mini PC (Intel N100 or better, 8 GB RAM, 256 GB SSD) with **Ubuntu 24.04** installed, or a Mac mini.
- A network cable from the mini PC to the router. Wi-Fi works, but a cable is more reliable.
- Receipt printers: network (Ethernet) printers are easiest. USB printers plug into the mini PC.

## 2. Install on the Ubuntu mini PC (about 10 minutes)

The code lives in a private GitHub repository (github.com/chimerembonu/corralbusiness-rms), so the mini PC needs either a GitHub login or a copy of the code on a USB stick.

**With a GitHub login:**
1. Plug the mini PC into the router with a cable and switch it on.
2. Open a terminal (Ctrl + Alt + T) and run these three commands, one at a time:

```bash
sudo apt-get install -y gh git
```

```bash
gh auth login
```

```bash
gh repo clone chimerembonu/corralbusiness-rms /opt/corralbusiness-rms && cd /opt/corralbusiness-rms && sudo ./install.sh
```

**With a USB stick:** copy the project folder from the Mac to the stick, plug it into the mini PC, open a terminal in that folder and run `sudo ./install.sh`.

3. Switch the receipt and kitchen printers on first: network printers plugged into the router, USB printers into the mini PC. The installer prepares USB printers by itself.
   The installer then prints the address and a 6-digit **setup code**, for example `http://192.168.8.10/setup` and `482913`.
4. Open that address on any computer, tablet or phone on the restaurant network, type the setup code and follow the setup screens: restaurant and TIN, owner login, taxes, receipt, tills and kitchen stations, printers (tap **Look for printers**, then **Print a test page** on each and tick which tills and stations it prints for), menu and staff from Excel (optional), and the backup password (write it down). At the end you are signed in to the back office.

Run `sudo ./install.sh` again at any time; it keeps your data and settings.

## 2b. Windows or Mac instead of Ubuntu

The same Docker package runs on a Windows 10/11 PC or a Mac. It is fine for a demo or a small restaurant; Ubuntu is still the recommended choice for the pilot because it needs less memory, restarts by itself after a power cut and costs nothing.

1. Install **Docker Desktop** (docker.com) and start it. On Windows it asks to enable WSL 2; say yes and restart.
2. Get the code: install GitHub Desktop or `gh`, sign in, and clone `chimerembonu/corralbusiness-rms` to a folder such as `C:\rms` or `~/rms`. Or copy the folder from a USB stick.
3. Make a copy of `.env.docker.example` called `.env` in that folder and fill in the lines marked "set by install": any long random text for the passwords and keys, `APP_KEY=base64:` followed by 32 random bytes in base64 (run `openssl rand -base64 32` on a Mac, or use the Mac's value), `APP_URL` and `RMS_BIND_IP` set to the computer's Wi-Fi address (Windows: `ipconfig`; Mac: System Settings → Wi-Fi → Details). Also add a line `RMS_USB_DIR=` followed by a folder of your own (for example `C:\rms-usb` or `/Users/you/rms-usb`); that is where backup copies go, since Windows and Mac have no `/media` folder for USB sticks. On a Mac, Docker Desktop must be allowed to see that folder (Settings → Resources → File sharing).
4. Open a terminal in the folder (Windows: PowerShell) and run:

```bash
docker compose up -d --build
```

5. Open `http://<the address>/setup` in a browser and follow the setup screens (restaurant, owner login, taxes, receipt, tills and kitchen stations, printers, menu and staff from Excel, backup password). On the printers screen, type your router's range in **Restaurant network** (for example `192.168.1.0/24`: the computer's address with the last number replaced by `0/24`), or put it in `.env` as `RMS_PRINTER_SUBNET=` beforehand. If you put a 6-digit `RMS_SETUP_CODE=` in `.env`, the first screen asks for it. Prefer the command line? `docker compose exec app php artisan rms:setup` does the basics instead.
6. Open `http://<the address>` on the tills. To start again after a reboot, open Docker Desktop (set it to start with the computer) and the app comes back by itself.

Printers: network printers work exactly as on Ubuntu. USB printers on Windows must be shared from Windows and used with connection "Browser" on a till, or plugged into the computer and set up in Docker Desktop's USB sharing (advanced).

## 3. Give the server a fixed address (do this once)

If the router hands out a new address, the tills lose the link. Fix it in one of two ways:

**Easiest: reserve the address on the router.**
1. On the mini PC run `hostname -I` and note the address (for example 192.168.8.10) and `ip link` for the MAC address.
2. Log in to the router (usually http://192.168.8.1 or http://192.168.1.1).
3. Find "DHCP reservation" / "Static lease" / "Address reservation" and bind the MAC address to that IP.

**Or: set it on the mini PC.**
1. Run `sudo nano /etc/netplan/01-rms.yaml` and paste (change the numbers to match your router):

```yaml
network:
  version: 2
  ethernets:
    enp1s0:            # run "ip link" to see the real name
      dhcp4: no
      addresses: [192.168.8.10/24]
      routes:
        - to: default
          via: 192.168.8.1
      nameservers:
        addresses: [192.168.8.1, 1.1.1.1]
```

2. Run `sudo netplan apply`.
3. Update `APP_URL` and `RMS_BIND_IP` in `/opt/corralbusiness-rms/.env` to the new address and run `sudo docker compose up -d`.

The app listens only on that address (`RMS_BIND_IP`), never on the internet side of the router.

## 4. Printers

The setup screens find printers, print a test page and assign them to tills and kitchen stations. To add or change printers later:

- **Network printer:** plug it into the router. In the router's settings, give it a fixed address (like the server, section 3), so it does not move. On the server run `./scripts/rms rms:printers:scan`; it lists the printers it finds with their addresses. Add each in Back office → Printers with connection "Network".
- **USB printer:** plug it into the mini PC, then run:

```bash
sudo ./scripts/usb-printers.sh
```

It gives the printer a print queue called `RMS_USB_1` (the next one `RMS_USB_2`, and so on). Add the printer in Back office → Printers with connection "USB" and that name as the address. `./scripts/rms rms:printers:queues` lists them.

## 5. Day to day

| Task | Command (in /opt/corralbusiness-rms) |
|---|---|
| Update to the latest version | `sudo ./scripts/update.sh` |
| Restart everything | `sudo docker compose restart` |
| See what is running | `sudo docker compose ps` |
| Logs of the app | `sudo docker compose logs -f app queue` |
| Any artisan command | `./scripts/rms <command>` e.g. `./scripts/rms rms:printers:scan` |
| Load the demo restaurant (test machine only) | `./scripts/rms rms:demo` |

If the computer restarts (power cut), everything comes back by itself.

## 6. Backups and restore

A backup is one zip file with the whole database plus uploaded logos and pictures. One is taken every night at 02:30 into `storage/backups` on the server; old ones are removed after 14 days.

**Backups are locked with a password** (AES-256), so a lost USB stick or downloaded file does not expose customers, staff or sales. The installer creates the password and shows it once at the end: **write it down and keep it away from the server.** You need it only to restore a backup on a *different* computer (for example after replacing the server). See it again any time with `./scripts/rms rms:backup --show-password`. Servers installed before 4 November 2026 use a password tied to the server's own key; run the same command to see it and write it down too. The nightly backup only protects you if a copy also leaves the server, so once a week do one of these:

- **USB stick:** plug a stick into the server and run `sudo ./scripts/backup-to-usb.sh`. Wait for "Done", then remove the stick.
- **Download:** in the back office open Settings → Backups and tap **Download** next to the newest backup; save it on your laptop or in cloud storage.

| Task | Command (in /opt/corralbusiness-rms) |
|---|---|
| Back up now | `./scripts/rms rms:backup` |
| Back up now and copy to a USB stick | `sudo ./scripts/backup-to-usb.sh` |
| See the backups on the server | `./scripts/rms rms:backups` |
| Restore the newest backup | `./scripts/rms rms:restore` |
| Restore a particular file | `./scripts/rms rms:restore rms-backup-2026-11-13-023000.zip` |
| Show the backup password | `./scripts/rms rms:backup --show-password` |

**Restoring after a disaster (new computer), in under 10 minutes:**
1. Install the app on the new computer (section 2) and answer the setup questions with anything; the restore replaces them.
2. Copy the backup zip to `/opt/corralbusiness-rms/storage/backups/` (from the USB stick: `sudo cp /media/*/*/rms-backup-*.zip /opt/corralbusiness-rms/storage/backups/`). If the folder does not exist yet, the Docker volume holds it: use `sudo docker compose cp /media/<user>/<stick>/rms-backup-2026-11-13-023000.zip app:/var/www/html/storage/backups/`.
3. Run `./scripts/rms rms:restore rms-backup-2026-11-13-023000.zip --password="the old server's backup password"` and answer **yes**. (The new server has its own password, so type the one you wrote down for the old server.)
4. Open the till; everything is back as at the time of the backup. The state from just before the restore is kept as another backup in case you chose the wrong file.

The back office can restore too (Settings → Backups → Restore, owner only, type RESTORE), and can take an uploaded backup file; for a file from another server, type that server's backup password in the box provided.

**Updates always back up first.** `scripts/update.sh` takes a backup before changing anything and **stops without updating** if the backup fails, so the restaurant keeps running on the old version. Only if the app cannot start at all (so no backup is possible) run `SKIP_BACKUP=1 sudo -E ./scripts/update.sh`.

## 7. Checking speed (load test)

`./scripts/rms rms:load-test --orders=120` simulates a lunch service on the demo restaurant (6 tills taking orders, kitchen bumping, screens loading) and prints how long each step takes and how many database queries it needs. Nothing is kept afterwards. The targets are: adding an item under 200 ms, the till screen under 1 s, the kitchen screen under 2 s. Run it on the mini PC after installing; if a step is far above its target, send the table to the developer.

For a true many-users test from another computer on the Wi-Fi, install `apache2-utils` and run, for example, `ab -n 500 -c 30 http://192.168.8.10/pos/login` (30 tills at once, 500 page loads); the "Time per request" line should stay well under one second.

## 8. Loading the real menu, ingredients and staff

Instead of typing everything, fill in a spreadsheet. In the back office open **Settings → Import data**:

1. Choose what to import (Menu items, Ingredients or Staff) and tap **Download template**. It opens in Excel, Numbers or Google Sheets and has example rows: replace them with yours and keep the first row as it is.
2. Menu: one row per item; an item with sizes gets one row per size with the size name and size price. Put "yes" in Count stock for bottled drinks.
3. Save as .xlsx (or .csv), upload it on the same page and tap **Check (preview)**. Each row shows New, Update or Error with the reason.
4. Fix any errors in the spreadsheet and upload again, then tap **Import now**. Importing the same file twice updates rows instead of duplicating them.

From the server's terminal the same works with `./scripts/rms rms:import menu menu.xlsx --dry-run` then without `--dry-run`.

## 9. Training new staff and the cheat-sheet

- **Training mode**: on the till home screen a manager taps **Start training mode** and enters their PIN. From then on, every order on that till is marked TRAINING on screen, on receipts and on kitchen tickets, and counts in no report, cash-up or stock. Other tills keep trading normally. Tap **End training mode** (manager PIN) before real service on that till.
- **Cheat-sheet**: Back office → Staff → **Cheat-sheet (PDF)** gives one page per role (waiters and cashiers, kitchen, riders, managers) with this restaurant's own addresses and till names. Print it and pin it up by the tills and in the kitchen.

## 10. Tills and screens

- Any browser works: Chrome or Chromium on Windows, Android tablets, iPads, phones.
- Till: `http://<server>/pos` · Kitchen: `http://<server>/kitchen` · Clock in: `http://<server>/clock` · Back office: `http://<server>/admin`.
- On a till, open the page, then "Add to home screen" / "Install app" so it opens full-screen.
- Printing from a till's own Windows printer: add it in Back office → Printers with connection "Browser".
