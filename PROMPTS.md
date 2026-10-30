# PROMPTS.md — What to paste into Claude Code, in order

How to use: open Terminal, go to the project folder, run `claude`, paste ONE prompt, wait for it to finish, do the "You check" steps, then paste the next prompt. If Claude Code asks you a question, answer it in plain words. If something breaks, paste the "Fix-it prompt" at the bottom.

Every prompt starts the same way so Claude Code re-reads the rules:

> Read CLAUDE.md and PLAN.md fully before doing anything. Confirm in one line which Phase/Step you are starting.

---

## PROMPT 0.1 — Set up my Mac and create the project

```
Read CLAUDE.md and PLAN.md fully before doing anything. Confirm in one line which Phase/Step you are starting.

I am non-technical and on a Mac. Start Phase 0, Step 0.1.

1. Check whether PHP 8.3+, Composer, Node 20+, and MySQL are installed. If not, give me numbered, ELI5 instructions to install Laravel Herd (free) and DBngin, wait for me to confirm, then verify again.
2. Create a new Laravel 12 project called corralbusiness-rms in this folder (the CLAUDE.md, PLAN.md and PROMPTS.md files are already here — keep them at the project root).
3. Configure .env for a local MySQL database named corralbusiness_rms, timezone Africa/Accra, currency GHS, app name "CorralBusiness – RMS".
4. Install and configure: Filament v4, Livewire 3, Tailwind (Vite), Pest, Laravel Reverb, spatie/laravel-permission, spatie/laravel-activitylog, mike42/escpos-php, barryvdh/laravel-dompdf, maatwebsite/excel.
5. Create the module folder structure from CLAUDE.md §3.4 with a ModuleServiceProvider that auto-loads each module's routes, migrations, views and Livewire components.
6. Initialise Git, commit, and confirm the site opens at http://corralbusiness-rms.test with a placeholder "CorralBusiness – RMS" page.
7. End with: what works now / what's next / anything you need from me.
```

You check: open `http://corralbusiness-rms.test` in Chrome and see the placeholder page.

## PROMPT 0.2 — Core schema, tenants, PIN login, money, tax engine

```
Read CLAUDE.md and PLAN.md fully before doing anything. Confirm in one line which Phase/Step you are starting.

Do Phase 0, Step 0.2 exactly as described in CLAUDE.md §3 (UUIDs, tenant_id/branch_id everywhere, soft deletes, outbox table, Money value object in pesewas, configurable tax engine with multiple components, roles from §3.10, PIN login with lockout, activity log). Search the web for the CURRENT Ghana VAT, NHIL, GETFund and any other levy rates on restaurant sales and seed them as defaults, clearly flagged for me to confirm with the accountant. Write Pest tests for tax maths, Money rounding and PIN lockout. Commit.
```

You check: nothing visual yet; Claude Code should report tests passing.

## PROMPT 0.3 — Menu module

```
Read CLAUDE.md and PLAN.md fully before doing anything. Confirm in one line which Phase/Step you are starting.

Do Phase 0, Step 0.3: the Menu module with Filament admin at /admin (categories, items, variants/sizes, modifier groups with required/optional/min/max/prices, combos, images, availability by day/time, sold-out flag, per-branch price override, allergens/tags). Build the Ghanaian DemoSeeder from CLAUDE.md §5 as `php artisan rms:demo` with the menu portion now (other data gets added in later steps). Create an admin user for me: email chimere@corralsystems.com, password you generate and show me once, PIN 1234. Commit.
```

You check: log in at `/admin`, open Menu, see jollof rice etc., change a price.

## PROMPT 0.4 — POS vertical slice

```
Read CLAUDE.md and PLAN.md fully before doing anything. Confirm in one line which Phase/Step you are starting.

Do Phase 0, Step 0.4: the Orders module and the full-screen touch POS at /pos. PIN login screen → choose order type (dine-in with table list, takeaway) → category tabs, item grid with big buttons, modifier popup, order panel with quantities/notes, Send to Kitchen, Pay (cash with on-screen keypad and change calculation), receipt preview as HTML sized for 80mm paper, and a simple "Today's sales" page. Every order change fires an event per CLAUDE.md §3.7. Tests for order totals with modifiers, discounts placeholder, and tax lines. Commit.
```

You check: on `http://corralbusiness-rms.test/pos`, enter PIN 1234, take an order, pay cash, see the receipt preview. Try it on your phone on the same Wi-Fi: Claude Code will tell you the address to type.

## PROMPT 0.5 — Kitchen display and printing

```
Read CLAUDE.md and PLAN.md fully before doing anything. Confirm in one line which Phase/Step you are starting.

Do Phase 0, Step 0.5: Kitchen module with one KDS screen at /kitchen fed live by Reverb (polling fallback), tickets with age colours, Start/Done bump by touch or keyboard. Printing module with the PrinterDriver interface and the NetworkEscPos and BrowserPrint drivers, queued PrintJob with retries, printer settings page in /admin, and a "Test print" button. Make queue worker and Reverb start automatically in Herd (or tell me the single command to start them). Commit.
```

You check: send an order from POS, watch it appear on `/kitchen` in a second window. If you have a network thermal printer, give Claude Code its IP address when asked and test-print.

## PROMPT 0.6 — Demo polish (Saturday evening)

```
Read CLAUDE.md and PLAN.md fully before doing anything. Confirm in one line which Phase/Step you are starting.

Do Phase 0, Step 0.6: complete rms:demo (tables, 6 tills, 3 stations, 10 staff with PINs, 20 ingredients), apply CorralBusiness – RMS branding (placeholder logo, colour scheme, receipt header), make a landing page at / with big buttons to POS, Kitchen, Admin, Clock-in. Run all tests, fix anything failing, make sure Phase 0 DoD in PLAN.md is fully met, and write me a one-page DEMO-SCRIPT.md: numbered steps to show the restaurant owner the demo in 5 minutes. Commit.
```

---

## PROMPT 1.1 → 1.6, 2.1 → 2.4, 3.1 → 3.5 — All later steps use this template

```
Read CLAUDE.md and PLAN.md fully before doing anything. Confirm in one line which Phase/Step you are starting.

Do Phase X, Step X.Y as described in PLAN.md and the matching module scope in CLAUDE.md §4. Before coding, list in 5 lines or fewer what you will build and any decision you need from me; if nothing is needed, proceed. Write Pest tests for the core flows. Run the full test suite. Update rms:demo if new data types were added. Commit. End with: what works now / what's next / anything you need from me, plus numbered steps for me to test it myself.
```

Replace X.Y with the step number, e.g. "Do Phase 1, Step 1.2".

Special notes for specific steps:
- **1.5 (USB printer):** plug the USB thermal printer into the Mac first. Tell Claude Code the printer's make/model. It will add the printer as a RAW queue in macOS (System Settings → Printers) and give you the queue name to enter in Settings.
- **1.6 (cash management):** tell Claude Code the restaurant's opening float (e.g. GHS 500) so the demo matches.
- **2.1 (staff):** have the real staff list ready (name, role, phone). Claude Code will build an Excel template; fill it; it imports.
- **3.1 (store server):** buy/borrow the mini PC (Ubuntu 24.04, 8GB RAM, SSD, wired Ethernet). Claude Code will give you an `install.sh` and numbered steps to run it on that machine.

## PROMPT — Go-live checklist (Phase 3.6, run at the restaurant)

```
Read CLAUDE.md and PLAN.md fully before doing anything. Confirm in one line which Phase/Step you are starting.

We are at the restaurant for Phase 3, Step 3.6. Walk me through the go-live checklist one item at a time, waiting for my confirmation on each: (1) real menu imported and spot-checked, (2) tax rates confirmed by the accountant and shown correctly on a test receipt, (3) each printer mapped to its station and test-printed, (4) each till named and tested on its actual device, (5) every staff member has a PIN and has clocked in once, (6) opening float entered, (7) training mode switched off and all training orders purged, (8) backup taken and restore rehearsed, (9) the owner can find the daily Z-report. Produce GO-LIVE-REPORT.md recording each item as passed, with the date and time.
```

## PROMPT — Post-go-live phases (4 → 8)

Same template as above with the phase number. Before Phase 6, tell Claude Code which VPS provider you chose; before Phase 7, which payment aggregator.

---

## Fix-it prompt (any time something breaks)

```
Read CLAUDE.md first. Something is wrong: [describe in plain words what you clicked and what you saw, or paste the red text on screen]. Diagnose and fix it without asking me to edit code. Run the tests. Tell me in two sentences what happened and what you changed. Commit.
```

## Hotfix-to-restaurant prompt (after go-live)

```
Read CLAUDE.md first. I have fixed/changed something on my Mac and committed it. Give me the numbered steps to put this update on the restaurant's store server safely (backup first), and confirm what I should test after.
```

## "I'm lost" prompt

```
Read CLAUDE.md and PLAN.md. Tell me, in plain English, which steps are complete (check Git history and tests), which step is next, and whether the app currently runs. Then give me the next prompt to paste.
```
