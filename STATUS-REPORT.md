# CorralBusiness – RMS — Independent status report

Date: 2 November 2026 · Repo: github.com/chimerembonu/corralbusiness-rms · 26 commits, 30 Oct – 2 Nov
Audited by: Claude (cloud session), from a fresh clone, independent of the Mac that built it.

## 1. Verdict

**Phases 0, 1, 2 and 3.1–3.5 are built, committed and tested. 228 automated tests pass (2,106 checks).** The app boots from a clean clone, the demo restaurant loads with one command, and every main screen responds (start page, POS, kitchen, clock-in, /admin, /hq).

Against PLAN.md you are **a week ahead of schedule**: everything up to Phase 3.5 was due by 12 Nov and is done on 2 Nov. What remains before go-live is 3.6 (go-live checklist at the restaurant) and 3.7 (two days of on-site shadowing) — and **one blocker below**.

## 2. Blocker — fix before touching the store server

**The Docker package for the restaurant server will not build.** The dependency lock file (`composer.lock`) was generated on your Mac, which has PHP 8.4, and it now contains packages that require PHP ≥ 8.4.1 (symfony/clock and others). But `docker/Dockerfile` uses the `php:8.3-fpm` image, and `composer.json` still claims PHP 8.2 is enough. On the Ubuntu mini PC the install would fail at the Composer step. I reproduced this on a PHP 8.3 machine.

Fix (one Claude Code session): change the Dockerfile base image to `php:8.4-fpm-bookworm`, set `"php": "^8.4"` in composer.json, re-check `install.sh` and `docs/STORE-SERVER.md` for any "PHP 8.3" mentions, and update CLAUDE.md §2 to say PHP 8.4. Then actually build the Docker image once on the Mac (`docker compose build`) before go-live day.

## 3. Other findings (should fix, not blockers)

1. **Network binding falls back to "everyone".** `install.sh` correctly binds the server to the LAN IP, but `docker-compose.yml` and `.env.docker.example` default to `0.0.0.0` if that step is skipped. Change the fallback to `127.0.0.1` so a missed step fails safe.
2. **Audit log gaps.** Orders, payments, cash movements, purchases and stock takes are logged; individual `OrderItem` changes and `StockMovement` rows are not. CLAUDE.md §3.11 requires both. Small fix.
3. **Possible extra queries on the deliveries board** (`customer` not preloaded). Cosmetic; only matters with many open deliveries.
4. **Not yet started (expected):** `OnlineOrdering` and `Sync` modules are empty placeholders for Phases 6–7. Nothing wrong; just confirming they aren't half-done.

## 4. What is verified present

- Architecture rules from CLAUDE.md §3: UUIDs + tenant/branch on every business table, soft deletes, outbox events, Money in pesewas (no float money anywhere), configurable Ghana tax components (VAT 15 %, NHIL 2.5 %, GETFund 2.5 % — confirmed by you on 1 Nov) printed line-by-line on receipts, 26 order events with listeners, printer driver interface with Network/USB/Browser drivers and a retrying print queue, hashed PINs with 5-attempt lockout, manager-PIN override on voids/discounts/refunds/day-close/training mode, indexes on the big tables, parameterised SQL only, no secrets committed.
- Phase 3 deliverables: Docker Compose (app, nginx, MySQL 8.4, queue, scheduler, Reverb), `install.sh`, `rms:setup`, `rms:printers:scan`, `rms:backup` nightly at 02:30 + `rms:restore` + USB export script, Excel/CSV importers with templates for menu, ingredients and staff, training mode, staff cheat-sheet PDF, `docs/STORE-SERVER.md`, `DEMO-SCRIPT.md`.
- Size: ~38,000 lines of PHP across 594 files, 13 modules, 19 admin resources, 15 POS/kitchen screens, 56 test files, English language files throughout (translation-ready).

## 5. What I could not verify from here

- Real printers (network ESC/POS, USB via CUPS, cash-drawer kick) — only testable with hardware.
- Reverb live updates in a browser (I confirmed the code and polling fallback, not the socket on a LAN).
- Performance with 6 tills — the load-test command exists; run it on the actual mini PC.
- The Docker build itself (no Docker in my environment) — which is why §2 must be done on your Mac.

## 6. Recommended order this week

1. Paste the Fix-it prompt for the PHP 8.4 blocker (§2) and the two small fixes (§3.1, §3.2). ~1 session.
2. Build the Docker image on the Mac and run the installer on the mini PC as soon as it arrives (Phase 3.1 "You check").
3. Plug the real printers in and complete Phase 1.5/3.1 hardware checks on site.
4. Run `php artisan rms:load-test` on the mini PC with 6 simulated tills.
5. Phase 3.6 go-live checklist with the restaurant; Phase 3.7 shadowing on 13–14 Nov.
