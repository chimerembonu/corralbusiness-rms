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

## Two tracks from 4 November 2026 (owner's decisions)

**Track A — the pilot** goes live on **13 November** on the current version (Phases 0–3). `main` is the pilot's code: until two weeks after go-live it only takes fixes the pilot needs.

**Track B — the Complete Edition** is built alongside on the branch **`complete-edition`**: every feature in CLAUDE.md §4 and more, **switched off by default**, so that installing for a new restaurant is "fill in the settings", not "write more code". Each stage is merged into `main` only when its tests pass and it is switched off, so it reaches the pilot through the normal safe update without changing anything the staff see.

Owner's decisions that shape Track B:
1. **Online service — both options:** **Corral Cloud** (hosted by Corral Systems; the restaurant types an activation code) **and self-hosted** (the restaurant's own online server, or a private link to its store server).
2. **Payment providers: Paystack and PaySwitch (theteller)**, behind one `PaymentGateway` interface so more can be added later.
3. **Sold two ways:** a **ready-made box** (mini PC pre-loaded; plug in, run the wizard) **and a download** (installer for a computer the restaurant already has).

### What "plug and play" means — the install-time checklist

Everything below is built in advance. At installation the restaurant (or Corral's installer) only does the right-hand column.

| Feature | Built in Track B | Filled in at installation |
|---|---|---|
| Setup wizard | Restaurant, branch, owner, taxes (2026 Ghana defaults), receipt, tills, printers (auto-found), menu/staff import from Excel | Answers to the wizard's questions |
| Licence & tiers | Signed licence key, checked offline; tiers switch features on; grace period, never a hard lock mid-service | Activation code |
| Features page | One switch per feature, each with "what it needs" checklist | Switches |
| Connections page | Encrypted storage of keys, **Test connection** button for each | Paystack keys / PaySwitch merchant ID + API user + key; SMS sender; WhatsApp; e-VAT credentials; Corral Cloud code |
| MoMo prompt at the till | Customer approves on phone; till checks status itself (works without inbound internet) | Payment provider keys |
| Online ordering | Customer web shop: menu, cart, pickup/delivery, zones & fees, pay online (provider's hosted page), order tracking, orders arrive on tills & kitchen | Corral Cloud code **or** own server address; delivery zones; opening hours |
| Owner's remote dashboard | Phone-friendly reports for all branches | Corral Cloud code **or** private link (Tailscale) |
| Off-site backups | Locked backups copied to the cloud nightly | Corral Cloud code or own storage |
| SMS / WhatsApp | Order confirmations, "ready", delivery updates, reservation reminders | Sender ID / API keys (MTech A2P route ready) |
| Recipes & food cost | Recipes per item/size, auto stock deduction, weighted-average cost, theoretical vs actual, margins | Recipes (or import) |
| Payroll (optional) | Pay rates, allowances/deductions, SSNIT and PAYE as editable tables, pay run, payslips | Rates confirmed by the accountant |
| Reservations | Bookings, table holds, deposits (via provider), reminders | Opening hours, table rules |
| Loyalty & gift vouchers | Points, vouchers (recorded as money owed until used), Corral Rewards connector | Programme rules |
| Accountant export | Daily journal to Excel, mapped to the restaurant's chart of accounts | Account codes |
| GRA e-VAT | Adapter built to GRA's published spec, tested against GRA's test service | GRA credentials, **after GRA approves the restaurant** |
| Delivery apps (Bolt Food, Glovo) | Quick "app order" entry on the till with the app's commission; automatic intake only if a partner API is granted | Commission % |
| Extras | Customer-facing display, barcode scanner, Twi/Ga/French language packs | Switch on |

**Cannot be pure configuration** (honest list): GRA e-VAT needs GRA's approval for each restaurant; delivery-app automatic intake needs partner approval from those companies; payment providers need a merchant account per restaurant; online ordering always needs either Corral Cloud or a server reachable from the internet.

### Track B stages (each ends with tests passing and the stage merged, switched off)

**E1 — Platform for plug and play** (wk of 9 Nov): Features page; Connections page (encrypted credentials, Test connection); licence key + tiers; first-run setup wizard; printer auto-discovery in the wizard.

**E2 — Payments** (wk of 16 Nov) — *core built 4 Nov: provider rulebook + registry, per-purpose default with automatic fallback, Paystack, PaySwitch and Demo adapters, MoMo prompt at the till, background re-checks, "needs a manager" list, guide for adding providers. Still to do: sandbox check with real test accounts, refunds through the provider from the till, reconciliation report.* `PaymentGateway` interface; **Paystack** adapter (MoMo charge for MTN/Telecel/AT, status polling, hosted checkout for cards); **PaySwitch** adapter (direct MoMo debit with r-switch MTN/VDF/ATL…, status check, hosted checkout for cards — *never* PaySwitch's direct card API, which would put card numbers on our server); MoMo prompt-to-pay at the till; refunds where the provider supports them; reconciliation report (till vs provider). Sandbox tests for both.

**E3 — Online service** (wks of 23 & 30 Nov): cloud deployment package (same app, `RMS_MODE=cloud`); tenant onboarding + activation codes (Corral Cloud); store↔cloud sync over the outbox/inbox (store wins for operations, cloud wins for menu/settings edited in HQ); `/hq` phone-friendly owner dashboard across branches; off-site backups; **self-hosted options**: install the cloud package on the restaurant's own server, or the private-link (Tailscale) remote access for the owner. D2 (branch scoping) lands first.

**E4 — Online ordering & messages** (wks of 7 & 14 Dec): customer web shop on the cloud side (menu, cart, pickup/delivery, zones & fees, opening hours, sold-out sync, pay via E2 hosted pages, tracking page); orders flow to the store's tills and kitchen through sync; SMS via MTech A2P / any HTTP SMS gateway; WhatsApp notifications.

**E5 — Back-of-house depth** (wk of 21 Dec): recipes/BOM per item & variant, auto-deduction, **weighted-average cost** (IAS 2), theoretical vs actual usage, COGS & margin reports, purchase price history; accountant's daily journal export.

**E6 — Payroll (optional)** (wk of 28 Dec): pay rates, overtime, allowances/deductions, SSNIT & PAYE as editable tables (accountant confirms), pay run, payslip PDF, Excel export.

**E7 — Guest features** (wk of 4 Jan): reservations & table holds, deposits via E2, reminders via E4; loyalty points with `LoyaltyProvider` (Corral Rewards connector); gift vouchers as a liability until used.

**E8 — Tax, channels & extras** (wk of 11 Jan): GRA e-VAT adapter to spec (enable after GRA approval); delivery-app order entry; customer-facing display; barcode scanner; language packs (Twi, Ga, French).

**E9 — Compliance & hardening** (runs alongside, finished by wk of 18 Jan): HTTPS on the store network; multi-factor sign-in for Owner/Manager; shorter back-office sessions; idle lock on tills (D1); customer data export & anonymise; retention clean-up; tamper-evident audit log; optional second-person approval; run tests on every GitHub change and protect `main`; D3, N3, N4, N6, N7; re-run security, role and compliance reviews on the finished edition.

**E10 — Packaging** (wk of 25 Jan): **box** — Ubuntu image that installs itself and opens the setup wizard on first boot; **download** — one-step installers for Ubuntu, Windows and Mac (Docker Desktop); install guide with pictures; Corral Cloud admin (activations, licences, support access with the restaurant's consent); independent penetration test before general sale.

**Target:** Complete Edition ready to sell by **end of January 2027**, proven in the pilot restaurant.

### Track A — pilot items (before and after 13 Nov)
- [ ] Go-live checklist (Phase 3.6) and two days of shadowing (3.7).
- [ ] Private remote link (Tailscale) for the owner's reports, optional, set up at installation.
- [ ] Business tasks from COMPLIANCE-ASSESSMENT.md §8 (DPC registration, GRA e-VAT/fiscal device question, PCI SAQ B, privacy notice, accountant confirmations).
- [ ] After two weeks of live trading: lift the `main` freeze and start merging Track B stages.

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
