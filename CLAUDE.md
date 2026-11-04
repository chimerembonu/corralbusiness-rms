# CorralBusiness – RMS (Restaurant Management System)

This file is read by Claude Code at the start of every session. It is the project's constitution. Follow it exactly. If the owner asks for something that contradicts it, say so and ask before proceeding.

## 0. Who you are working with

- The owner (Chimere) is **non-technical**. He runs the project alone with you. He will maintain the system himself.
- Every instruction you give him must be **numbered, plain-English, ELI5 style**. Never assume he knows a term (explain "run a migration" as "update the database structure", etc.).
- Never ask him to write or edit code. You write all code. If a file needs a value from him (e.g. a printer's IP address), ask for the value and put it in the file yourself.
- When something fails, do not dump error logs on him. Diagnose, fix, then tell him in one or two sentences what happened.
- At the end of every session, print a short "What works now / What's next / Anything I need from you" summary.

## 1. Product summary

**CorralBusiness – RMS** is a lightweight, offline-first restaurant management system by Corral Systems Limited (Ghana). It runs on a small server computer inside each restaurant (the "store server"); staff use it from touch screens, tablets, and phones on the restaurant Wi-Fi. It keeps working with no internet. An optional cloud instance provides online ordering, remote owner dashboards and backups, and syncs with the store when internet is available.

It is a **product**, not a one-off: multi-restaurant (tenant), multi-branch from day one.

### Pilot (hard constraints)
- Real restaurant, go-live target: **13 November 2026** (two weeks from 30 Oct 2026).
- 5–6 tills, 30+ staff, dine-in + takeaway + own-rider delivery.
- First clickable demo: **31 October 2026**.
- Scope discipline: anything not in Phase 0–3 of PLAN.md is **post-go-live**. If asked to add it earlier, warn that it risks the go-live date, and only proceed if the owner confirms.
- **Two tracks (owner, 4 Nov 2026; PLAN.md "Two tracks").** `main` = the pilot: until two weeks after go-live it takes pilot fixes only. The **Complete Edition** (stages E1–E10) is built on the branch **`complete-edition`**; every new feature is **off by default** and configured at installation (Features and Connections pages, setup wizard, licence). Payment providers: **Paystack and PaySwitch** via one `PaymentGateway` interface — hosted checkout pages for cards only, never a direct card API. Online service: **Corral Cloud and self-hosted** both supported. Sold as a **ready-made box and a download**.

## 2. Tech stack (fixed — do not substitute)

| Layer | Choice |
|---|---|
| Language / framework | PHP 8.4+, Laravel 12 |
| Back-office admin | Filament v4 (panels: `/admin` for store managers, `/hq` for the tenant owner across branches) |
| POS, Kitchen Display, Clock-in screens | Livewire 3 + Alpine.js + Tailwind (full-screen, touch-first, big targets ≥ 48px) |
| Realtime (KDS, order status) | Laravel Reverb (WebSockets) with automatic polling fallback if the socket drops |
| Database | MySQL 8 (MariaDB 10.6+ acceptable). SQLite only for automated tests. |
| Queues / scheduler | Laravel queues (database driver; Redis optional), Laravel scheduler |
| Receipt / kitchen printing | `mike42/escpos-php` (ESC/POS) for thermal printers, plus browser print (HTML → `window.print()`) as a fallback for any printer installed on a till |
| PDF/Excel exports | `barryvdh/laravel-dompdf`, `maatwebsite/excel` |
| Auth / permissions | Laravel auth + `spatie/laravel-permission`; staff use 4–6 digit PINs on POS |
| Local dev (owner's Mac) | Laravel Herd (bundles PHP, Composer, Node, nginx) + DBngin for MySQL |
| Store server (restaurant) | Ubuntu 24.04 mini PC or Mac mini, Docker Compose (`docker compose up -d`), one-line installer script |
| Tests | Pest. Every module ships with feature tests for its core flows. |
| Package manager | Composer + npm (Vite) |

Translation-ready: all user-facing strings go through `__()` with keys in `lang/en/*.php`. Default locale `en`, currency `GHS`, timezone `Africa/Accra`.

## 3. Architecture rules (non-negotiable)

1. **Offline-first.** Nothing in the in-store flow (ordering, payments, kitchen, printing, reports) may depend on the internet. External calls (payment aggregators, SMS, cloud sync) run in queued jobs with retries and never block a till.
2. **UUID primary keys everywhere** (`Str::uuid7()`), plus `tenant_id` and `branch_id` on every business table. This is what makes cloud sync and multi-branch possible later. Add these even in Phase 0.
3. **Sync-ready from day one, sync built later.** Every table has `created_at`, `updated_at`, `deleted_at` (soft deletes). Business events are also written to an append-only `outbox` table (`id, tenant_id, branch_id, event_type, payload json, created_at, synced_at`). Phase 6 consumes this; until then it just accumulates.
4. **Modular monolith.** One Laravel app, code organised by module under `app/Modules/<Name>/` (Menu, Orders, Pos, Kitchen, Payments, Inventory, Staff, Customers, Reports, Printing, Sync, OnlineOrdering, Settings). Each module has its own `Models/`, `Services/`, `Livewire/`, `Filament/`, `Events/`, `Jobs/`, `routes.php`, `migrations/`, `tests/`. Modules talk via services and events, not by reaching into each other's tables. Plugin-style: a module can be switched off per tenant in Settings.
5. **Money is integers in pesewas** (never floats). Use a `Money` value object. Display through a single `money()` helper.
6. **Taxes are configurable, never hard-coded.** Tax engine supports multiple components (VAT, NHIL, GETFund, COVID levy or whatever Ghana's current regime is), inclusive or exclusive pricing, and per-item exemption. Before seeding default rates, **search the web for the current Ghana VAT and levy rates** and tell the owner to confirm them with the restaurant's accountant. Receipts must show each component.
7. **Every order change is an event** (`OrderCreated`, `ItemAdded`, `ItemVoided`, `OrderPaid`, `OrderSentToKitchen`…). Listeners handle printing, KDS updates, stock deduction, outbox writes. Keep controllers/Livewire thin; put logic in Services.
8. **Hardware abstraction.** `Printing` module has a `PrinterDriver` interface with drivers: `NetworkEscPos` (host:port, default 9100), `UsbEscPos` (via the server's CUPS/raw queue name on Linux/Mac, `\\.\COMx` or share name on Windows), `BrowserPrint`. Printers are configured in Settings (name, driver, address, paper width 58/80mm, assigned stations). Print jobs are queued (`PrintJob`) and retried; failures appear on a "Printer problems" banner in POS, never as a crash.
9. **Touch-first UI.** POS, KDS and clock-in screens: full-screen, no hover-only actions, buttons ≥ 48px, works at 1024×768 and on 10" tablets, keyboard optional. Numeric keypads on screen for PINs, cash tendered, quantities.
10. **Permissions on everything.** Roles: Owner, Manager, Cashier, Waiter, Kitchen, Rider, Inventory, Accountant. Manager PIN override required for voids, refunds, discounts above a threshold, reopening a closed day, price edits. Owner's decisions (4 Nov 2026, see ROLE-ACCESS-REVIEW.md): waiters need a manager PIN for **every** discount and promo code; only Kitchen, Manager and Owner use the kitchen screen; the Inventory role sees stock only, never sales or cash; the Accountant has no till login. Who-can-do-what lives in `RoleDefinitions.php` and is guarded by `tests/Feature/RoleAccessMatrixTest.php` and `RoleActionMatrixTest.php`.
11. **Audit trail.** `activity_log` (spatie/laravel-activitylog) on orders, payments, stock movements, staff changes, settings.
12. **No destructive migrations once Phase 3 is live.** Only additive migrations after go-live; data fixes via idempotent commands.
13. **Performance targets:** POS screen loads < 1s on the LAN; adding an item < 200ms; KDS reflects a new ticket < 2s; works with 6 tills and 30 concurrent staff on a 4-core mini PC.
14. **Security basics:** CSRF everywhere, rate-limit PIN attempts (5 wrong → 60s lockout), hash PINs, HTTPS on cloud, LAN-only binding on the store server by default, daily automated MySQL dump to `storage/backups` and (Phase 6) to the cloud.

## 4. Module scope (what "complete" means)

**Menu**: categories, items, variants/sizes, modifiers (groups, required/optional, min/max, priced), combos, item images, availability by time/day, 86-ing (sold out) from POS, per-branch price overrides, allergens/tags.

**Orders / POS**: table map (floor plan with drag-to-arrange, statuses), open/merge/transfer tables, dine-in/takeaway/delivery order types, seat-level items, notes, hold & recall, course/fire control, void with reason + manager PIN, discounts (item/order, %/fixed, preset reasons, promo codes), service charge, tips, split bill (by item, by amount, by seats), multiple payments per order, re-print, order history and search, offline queue indicator.

**Kitchen**: stations (Grill, Fry, Bar, Dessert…), routing rules per item/category, KDS screen per station (new / in-progress / done, bump bar via keyboard or touch, colour by age, recall), expo screen, ticket printing per station, "all day" counts.

**Payments**: cash (drawer open, change calc), Mobile Money (MTN MoMo, Telecel Cash, AT Money — manually recorded at launch, with transaction reference field), card (manual at launch), on-account/credit customers, refunds, cash-up/till reconciliation per cashier, cash drawer events (pay-in/pay-out), end-of-day close with Z-report. **Payment providers (Complete Edition E2):** every provider is an adapter behind the `PaymentGateway` interface (`app/Modules/Payments/Gateways`), registered in `config/payments.php`; the owner picks a default per purpose on Settings → Payment providers. Built: Paystack, PaySwitch, Demo (training only). To add one, follow `docs/ADDING-A-PAYMENT-PROVIDER.md` exactly; `GatewayContractTest` must pass.

**Inventory**: ingredients with units (kg, g, L, ml, pcs, custom), suppliers, purchase orders → goods received, stock levels per branch, stock adjustments and wastage with reasons, stock takes (count sheets), low-stock alerts, recipes (BOM) per menu item/variant with auto-deduction on sale, cost of goods and theoretical vs actual usage, inventory valuation.

**Staff**: staff profiles, roles/permissions, PIN login, clock in/out on any screen (with photo stub for later), shift rosters (weekly, by role), timesheets, sales per staff, Excel export of timesheets. **Payroll (optional module, off by default)**: pay rates (hourly/monthly), overtime rule, deductions/allowances, pay run generation, payslip PDF, Excel export; disabled → Excel export of hours only.

**Customers**: customer records (name, phone), order history, addresses, notes, discounts/promo codes, simple points balance with `LoyaltyProvider` interface so Corral Rewards can plug in later.

**Reports**: live dashboard, daily Z-report, sales by day/hour/item/category/order type/staff/payment method, voids & discounts report, tax report, food cost & gross margin, inventory valuation and movement, staff hours, all exportable to Excel/PDF, date-range filters, branch filter in `/hq`.

**Online ordering (Phase 7)**: customer web app at `/order` (menu, cart, pickup or delivery, delivery zones with fees by polygon/radius, order tracking page, SMS/WhatsApp notification hooks). Runs on the cloud instance; orders flow to the store via Sync.

**Sync (Phase 6)**: store ↔ cloud via outbox/inbox over HTTPS with API tokens; conflict rule = store wins for operational data, cloud wins for menu/settings edited in HQ; status screen showing last sync time and pending count.

**Settings**: restaurant/branch profile, receipt header/footer/logo, tax components, printers & stations, order types, service charge, rounding rules, modules on/off, number sequences, users & roles, backup now/restore.

## 5. Conventions

- Models in `App\Modules\<Module>\Models`; Services return DTOs or models, throw domain exceptions (`InsufficientStockException`, `ManagerPinRequiredException`).
- Form requests for validation. Enums (PHP 8.1 backed) for statuses.
- Seeders: `DemoSeeder` creates a realistic Ghanaian restaurant (jollof, waakye, banku & tilapia, kelewele, fried rice, Club beer, Malta, sobolo…) with 3 stations, 6 tills, 12 tables, 10 staff, 20 ingredients with recipes. Run with `php artisan rms:demo`.
- Feature flags per tenant in `module_settings` table.
- Commit after every completed step with a clear message. Never leave the app in a broken state at the end of a session; if mid-feature, hide it behind a flag.
- Run `php artisan test` before declaring a phase done. Run `./vendor/bin/pint` to format.
- Document every env variable in `.env.example` with a comment.

## 6. Commands cheat-sheet (for the owner)

- Start the app on the Mac: Herd runs it automatically at `http://corralbusiness-rms.test`
- Update database structure: `php artisan migrate`
- Load demo restaurant: `php artisan rms:demo`
- Run tests: `php artisan test`
- Build front-end: `npm run build`
- Start realtime server: `php artisan reverb:start`
- Start queue worker (printing!): `php artisan queue:work`
- Back up database now: `php artisan rms:backup`

Claude Code: when any of these should be run, run it yourself; only tell the owner the command if he must run it on a machine you cannot access.
