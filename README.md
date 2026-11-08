# CorralBusiness – RMS

Offline-first restaurant management system by Corral Systems Limited (Ghana).
Laravel 12 · Filament 4 · Livewire 3 · Reverb · MySQL.

Read [CLAUDE.md](CLAUDE.md) (rules), [PLAN.md](PLAN.md) (phases) and [PROMPTS.md](PROMPTS.md) (what to paste into Claude Code).

## Install at a restaurant (one command)

On the restaurant's Ubuntu 24.04 computer:

```bash
curl -fsSL https://raw.githubusercontent.com/chimerembonu/corralbusiness-rms/main/install.sh | sudo bash
```

Then open the address it shows (`http://<server>/setup`), type the setup code and follow the setup screens. Mac, Windows, printers, backups and HTTPS: [docs/STORE-SERVER.md](docs/STORE-SERVER.md).

## Local development (Mac)

1. Install Laravel Herd and DBngin (or Homebrew MySQL on port 3306).
2. `composer install && npm install`
3. Copy `.env.example` to `.env`, run `php artisan key:generate`.
4. `php artisan migrate`
5. `npm run build`
6. Open http://corralbusiness-rms.test

Cheat-sheet of daily commands: CLAUDE.md §6.

Start the background services (printing queue + realtime kitchen updates) with one command and leave it running:

```bash
composer run rms
```
