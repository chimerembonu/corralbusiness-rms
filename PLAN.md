# CorralBusiness – RMS: Build Plan

Dates assume work starts Fri 30 Oct 2026. Go-live Thu 13 Nov 2026. Each phase has a **Definition of Done (DoD)** — the phase is not finished until every DoD line is true and `php artisan test` passes.

Rule of thumb for the owner: one Claude Code session ≈ one step. Paste the matching prompt from PROMPTS.md, let it finish, test what it tells you to test, then move on.

---

## Phase 0 — Foundation + clickable demo (Fri 30 Oct → Sat 31 Oct)

Goal: by Saturday evening, the owner can take a dine-in order on a touch screen, see it on a kitchen screen, print a ticket (or see the printed-ticket preview), take cash, and see today's sales.

Steps
0.1 Environment on the Mac (Herd, DBngin/MySQL, Node), create Laravel 12 project `corralbusiness-rms`, Git init, Filament, Livewire, Tailwind, Pest, Reverb, spatie permission, activitylog, escpos-php.
0.2 Core schema: tenants, branches, users (with PIN), roles, settings, modules, outbox, activity_log. Multi-tenant scoping trait. UUIDs. Money value object. Tax engine (components, configurable).
0.3 Menu module (categories, items, variants, modifiers, availability, 86) with Filament admin.
0.4 Orders + POS (vertical slice): order types, table list, add items with modifiers, send to kitchen, cash payment, receipt preview, Z-report-lite.
0.5 Kitchen: one KDS screen fed by Reverb, bump/done, ticket print job via `BrowserPrint` and `NetworkEscPos`.
0.6 Demo seeder `rms:demo`. Branding: "CorralBusiness – RMS" logo placeholder, colours, receipt header.

DoD
- `http://corralbusiness-rms.test/pos` works on the Mac in Chrome, touch-sized.
- Demo data loads with one command.
- Order → kitchen screen → payment → report, end to end, no internet.
- Tests pass for order totals, tax maths, PIN login.

## Phase 1 — Complete POS & kitchen (Sun 1 Nov → Wed 4 Nov)

1.1 Floor plan editor (drag tables, sections), table statuses, merge/transfer, seats.
1.2 Full order ops: hold/recall, notes, course firing, void with reason + manager PIN, discounts & promo codes, service charge, tips, split bill, multi-payment, MoMo/card manual recording with reference, reprint.
1.3 Delivery orders: customer phone/name/address capture, assign rider, rider status (out / delivered), delivery fee.
1.4 Kitchen: multiple stations, routing rules, per-station KDS + printers, expo screen, "all day" view, ticket ageing colours.
1.5 Printing: printer settings UI; `UsbEscPos` driver (via CUPS raw queue on Mac/Linux); cash drawer kick; receipt designer (header/footer/logo/VAT number); printer-problem banner; retry.
1.6 Cash management: cashier sessions, float, pay-in/out, cash-up, X and Z reports, day close with manager PIN.

DoD
- A full lunch service can be simulated with 2 browser windows as tills + 1 KDS window.
- A ticket prints to a real network thermal printer AND to a USB printer connected to the Mac.
- Voids/discounts require a manager PIN; everything appears in the activity log.

## Phase 2 — Staff & basic stock (Thu 5 Nov → Sat 7 Nov)

2.1 Staff module: profiles, roles, PIN management, clock in/out screen, timesheets, rosters, sales-per-staff, Excel export of hours.
2.2 Inventory (basic): ingredients & units, suppliers, stock levels, purchases/goods received, adjustments & wastage, stock take sheets, low-stock alerts. Simple "track stock" toggle on menu items (count-based) for items like drinks.
2.3 Customers: records, phone lookup at POS, order history, on-account customers.
2.4 Reports v1: dashboard, sales by day/hour/item/category/type/staff/payment, voids/discounts, tax report, Excel/PDF export.

DoD
- 30 demo staff can log in by PIN on any till; a roster for next week can be built and exported.
- Drinks stock decreases on sale; a stock take can be done on a phone.
- Manager can export yesterday's sales to Excel.

## Phase 3 — Hardening, deployment, go-live (Sun 8 Nov → Thu 13 Nov)

3.1 Docker Compose package for the store server (app, MySQL, Reverb, queue worker, scheduler, nginx) + `install.sh` for Ubuntu mini PC; LAN-only binding; static IP guidance; printer discovery helper (`php artisan rms:printers:scan`).
3.2 Backups: nightly MySQL dump + restore command; USB-stick export.
3.3 Load test with 6 simulated tills; fix slow queries; add indexes.
3.4 Data entry tools: Excel import for menu items and ingredients; staff bulk import.
3.5 Training mode flag (orders marked TRAINING, excluded from reports); staff cheat-sheet PDF generated from the app.
3.6 Go-live checklist (PROMPTS.md § Go-live) executed with the owner at the restaurant: real menu, real taxes confirmed by accountant, printers mapped, tills named, staff PINs issued, day-close rehearsed.
3.7 Two days of on-site shadowing with a hotfix loop (bugs → fix on Mac → `git pull` + `docker compose up -d` on server).

DoD
- Restaurant trades on CorralBusiness – RMS for a full day with no internet dependency.
- Owner can restore from a backup in under 10 minutes following numbered steps.

---

## Post-go-live phases (sequence may change based on the pilot's pain points)

## Post-go-live week 1 — security follow-ups (week of 16 Nov, before Phase 4 work starts)
From the two security review passes (SECURITY-REVIEW.md, SECURITY-REVIEW-2.md). None blocks the pilot; all are small.

- [ ] **N3** Login lockout: add a per-network-address ceiling in `PinAuthenticator::attempt` so a browser cannot get 5 tries per till across every till (`Pos/Livewire/PinLogin.php`).
- [ ] **N4** Log every quantity decrease on a sent line (before the bill is printed too) and show it in the voids & discounts report, like reductions after the bill already are.
- [ ] **N6** Kitchen bump/recall and rider assignment: look up tickets and riders with `->forBranch()` (`Kitchen/Livewire/KitchenDisplay.php`, `ExpoDisplay.php`, `Pos/Livewire/DeliveriesBoard.php`).
- [ ] **N7** Backup restore/delete: add `->authorize()` next to `->visible()` so Owner-only is explicit (`Settings/Filament/Pages/BackupsPage.php`).
- [ ] **D1** Idle lock on a shared till: configurable idle timeout in Till settings and auto sign-out in the till layout. Until then the cheat-sheet says "tap Sign out when you leave the till".
- [ ] **D2** Branch scoping of `/admin` lists (cashier sessions, day closes, stock takes, purchases, stock movements) and the kitchen broadcast channel. Must land before Phase 6 (cloud / HQ).
- [ ] **D3** On-account receipts taken in the back office land in no drawer: take them at a till only, or add a drawer choice to the back-office form. Until then the restaurant is told to receive account payments at a till.
- [ ] Re-run the security review after the first week of live trading.

## Phase 4 — Recipe-level inventory & food cost (Week of 16 Nov)
Recipes/BOM per item & variant, auto-deduction, theoretical vs actual usage, COGS and margin reports, inventory valuation, purchase price history, supplier price comparison.

## Phase 5 — Staffing extras & optional Payroll (Week of 23 Nov)
Roster-vs-actual, overtime flags, leave requests; Payroll module (off by default): pay rates, allowances/deductions, pay run, payslip PDF, Excel export, SSNIT/PAYE as configurable formulas (owner confirms rates).

## Phase 6 — Cloud instance & sync (Weeks of 30 Nov – 7 Dec)
Cloud deployment (Docker on a VPS), tenant onboarding, `/hq` owner panel across branches, store↔cloud sync via outbox/inbox, conflict rules, sync status screen, off-site backups, owner mobile dashboard (responsive `/hq`).

## Phase 7 — Online ordering & payment gateways (Weeks of 14 – 21 Dec)
Customer web app `/order` (pickup/delivery, zones, tracking), `PaymentGateway` interface with first aggregator (owner picks Hubtel / Paystack / Flutterwave), MoMo prompt-to-pay at POS via the same gateway, SMS notifications via MTech's A2P route, WhatsApp order notifications.

## Phase 8 — Growth features (2027)
Reservations, loyalty via Corral Rewards (`LoyaltyProvider`), gift vouchers, third-party aggregator order intake (Bolt Food / Glovo), GRA E-VAT certified invoicing, accounting export (QuickBooks/Xero/Excel journal), multi-language packs (Twi/Ga/French), customer-facing display, barcode scanner support.

---

## Risks and how the plan handles them

| Risk | Mitigation |
|---|---|
| Two-week deadline | Phases 0–3 are deliberately lean; everything else is post-go-live. CLAUDE.md tells Claude Code to refuse scope creep before go-live. |
| USB printers from a browser | Printing goes through the server (CUPS raw queue / network 9100) via a queue worker, never from the browser, with browser print as fallback. |
| Wi-Fi drops in the restaurant | App runs on the LAN; tills are browser tabs that reconnect; Reverb falls back to polling; order drafts cached in browser `localStorage` until saved. Recommend wired Ethernet for tills and printers. |
| Owner is non-technical | Numbered instructions, one-line installer, backup/restore commands, hotfix loop documented. |
| Ghana tax changes | Configurable tax components; receipt shows each. |
| Data loss | Nightly dumps + USB export now; cloud backups in Phase 6. |
