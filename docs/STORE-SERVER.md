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

3. Answer the questions: restaurant name, branch name, your name, email, password and till PIN.
4. The installer prints the address, for example `http://192.168.8.10`. Open it on a till.

Run `sudo ./install.sh` again at any time; it keeps your data and settings.

## 2b. Windows or Mac instead of Ubuntu

The same Docker package runs on a Windows 10/11 PC or a Mac. It is fine for a demo or a small restaurant; Ubuntu is still the recommended choice for the pilot because it needs less memory, restarts by itself after a power cut and costs nothing.

1. Install **Docker Desktop** (docker.com) and start it. On Windows it asks to enable WSL 2; say yes and restart.
2. Get the code: install GitHub Desktop or `gh`, sign in, and clone `chimerembonu/corralbusiness-rms` to a folder such as `C:\rms` or `~/rms`. Or copy the folder from a USB stick.
3. Make a copy of `.env.docker.example` called `.env` in that folder and fill in the lines marked "set by install": any long random text for the passwords and keys, `APP_KEY=base64:` followed by 32 random bytes in base64 (run `openssl rand -base64 32` on a Mac, or use the Mac's value), `APP_URL` and `RMS_BIND_IP` set to the computer's Wi-Fi address (Windows: `ipconfig`; Mac: System Settings → Wi-Fi → Details).
4. Open a terminal in the folder (Windows: PowerShell) and run:

```bash
docker compose up -d --build
```

```bash
docker compose exec app php artisan rms:setup
```

5. Open `http://<the address>` on the tills. To start again after a reboot, open Docker Desktop (set it to start with the computer) and the app comes back by itself.

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

- **Network printer:** plug it into the router. On the server run `./scripts/rms rms:printers:scan`; it lists the printers it finds with their addresses. Add each in Back office → Printers with connection "Network".
- **USB printer:** plug it into the mini PC, then run:

```bash
sudo apt-get install -y cups
sudo lpadmin -p RECEIPT -E -v "$(lpinfo -v | grep -i usb | head -1 | awk '{print $2}')" -m raw
./scripts/rms rms:printers:queues
```

Add the printer in Back office → Printers with connection "USB" and address `RECEIPT`. (The installer links the computer's print system into the app; re-run `sudo ./install.sh` if CUPS was installed afterwards.)

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

## 6. Tills and screens

- Any browser works: Chrome or Chromium on Windows, Android tablets, iPads, phones.
- Till: `http://<server>/pos` · Kitchen: `http://<server>/kitchen` · Clock in: `http://<server>/clock` · Back office: `http://<server>/admin`.
- On a till, open the page, then "Add to home screen" / "Install app" so it opens full-screen.
- Printing from a till's own Windows printer: add it in Back office → Printers with connection "Browser".
