# CorralBusiness – RMS — Independent security review

Date: 2 November 2026 · Commit reviewed: `bffc8d7` on `main`
Method: three independent read-only reviewers (access control & tenant isolation; injection, files & infrastructure; money & fraud resistance), each finding confirmed by reading the code path, headline items re-verified by hand. Nothing in the repository was modified. Line numbers refer to the commit above.

## Scorecard

| Severity | Count | Meaning |
|---|---|---|
| Critical | 1 | Do not go live until fixed |
| High | 5 | Fix before go-live (all are small, 1–2 Claude Code sessions in total) |
| Medium | 8 | Fix in the first two weeks after go-live |
| Low | 5 | Hardening; schedule when convenient |

**Rating today: NOT YET FIT FOR PILOT** — because of the Critical and the five Highs. **After those six fixes: fit for pilot.** After the Mediums: fit to sell to a second restaurant. The underlying design is sound (see "What is solid" at the end); these are gaps in the last mile, not structural problems.

## Critical

**C1. Manager-PIN approval can be skipped entirely.**
Plain English: the screen asks for a manager's PIN before a void, refund, discount, waived service charge, short cash-up, day close/reopen, stock-take sign-off or training mode. But the step that *does* the action is a separate "…Approved" function that is also reachable directly from the browser, and it accepts a manager's ID instead of a PIN. A cashier who learns any manager's ID (rider IDs are visible on the deliveries board; staff IDs are visible in the back office) can run the action and the manager is recorded as having approved it.
Where: `app/Modules/Pos/Concerns/AsksForManagerPin.php` (`$approval` not locked; line 47 dispatches to public methods); public `*Approved` methods in `Pos/Livewire/OrderScreen.php:529,591,657`, `OrdersList.php:79`, `CashScreen.php:240,290,317`, `OrderTypeScreen.php:129`, `Inventory/Livewire/StockTakeScreen.php:140`.
Fix: make every `*Approved` method `protected`; mark `$approval` with `#[Locked]`; have `approve()` be the only caller. Add a test that calls each `*Approved` method directly and expects it to be rejected.

## High

**H1. Training mode has no server-side permission check.** Combined with C1, any till user can switch a till into training mode and ring real sales that never reach reports, the drawer count or stock. `Pos/Services/TrainingMode.php:22`, `OrderTypeScreen.php:129`. Fix: `abort_unless($manager->can(Permission::TrainingMode))` inside `trainingApproved`, and log every toggle (already partly done).

**H2. Backup download reads any file on the server.** A Manager (not only Owner) can request `/backups/download?file=/path/to/.env` and obtain the encryption key and database password. `Settings/Services/BackupService.php:164-173` (`resolve()`), `Settings/Http/BackupDownloadController.php:16-23`, delete action in `Filament/Pages/BackupsPage.php:120` has the same flaw (arbitrary file delete). Fix: in `resolve()` always `basename()`, require the `rms-backup-…zip` pattern, `realpath()` must sit inside the backups directory; gate delete to Owner.

**H3. Backup restore can plant code on the server ("zip-slip").** Restore extracts a zip without checking entry names; a crafted zip can write files outside the target, and anything put under `uploads/` is web-served, where nginx will execute `.php`. `BackupService.php:94-101`, `docker/nginx/default.conf:27-33`. Fix: validate every zip entry (no `..`, no leading `/`, only `database.sql`, `manifest.json`, `uploads/*` with image extensions); add `location ~* ^/storage/.*\.php$ { deny all; }` to nginx.

**H4. Order/receipt links are not tenant- or branch-checked on first load.** Tenant scoping is applied *after* the record is looked up, so `/pos/orders/{id}`, `/pos/orders/{id}/receipt`, `/print/jobs/{id}`, `/customers/{id}/statement`, `/inventory/stock-takes/{id}/print`, `/pos/cash/sessions/{id}` will show another branch's (and on a shared cloud server, another restaurant's) record to anyone who has the ID. `bootstrap/app.php:17`, `app/Support/Tenancy/TenantScope.php:14`. Fix: run `SetTenantFromUser` before route-model binding (middleware priority) and assert `branch_id` matches in POS mounts and controllers.

**H5. Lines can be removed and unsent orders cancelled with no PIN, no audit entry, and no report line.** Classic scam: ring a drink, print the bill, pocket the cash, remove the line, cancel the order — invisible in the voids report. `Orders/Services/OrderService.php:178-188, 546-551`, `OrderScreen.php:753-769`; `OrderItem` is not audited. Fix: record `bill_printed_at`; once a bill is printed, removal/cancel requires the void path (PIN + reason); make `OrderItem` auditable; show removed lines and till-cancelled orders in the voids & discounts report.

## Medium

**M1.** Discount threshold is checked per discount, so stacking 10 % on each line plus 10 % on the order (19 % total) never asks for a PIN; item discounts don't store who approved. `OrderService.php:649-660, 266-286`. Fix: test the cumulative order discount ratio; store approver on item discounts.
**M2.** Double-tap on Pay (or two tills on one order) can record two payments and fire paid/print/outbox events twice; "send to kitchen" can deduct stock twice. `Payments/Services/PaymentService.php:72-120`, `OrderService.php:486-512`, `Inventory/Services/StockService.php:141`. Fix: `lockForUpdate()` the order inside the transaction and re-check balance/status; give stock deduction an idempotency key like restock already has.
**M3.** Cash pay-outs have no limit and no PIN; a cashier can remove the whole drawer with a typed reason. `Payments/Services/CashService.php:117-124`. Fix: manager PIN above a configurable threshold.
**M4.** Delivery fee is free text from any POS user and flows into the total. `Orders/Services/DeliveryService.php:40-41`. Fix: default from settings; override needs a price-edit permission.
**M5.** Splitting a training order produces a real order (training flag not copied). `OrderService.php:418-435`. Fix: copy `is_training`.
**M6.** No Filament policies: Inventory and Accountant roles (which hold `admin.access`) can edit menu prices, create promo codes and raise the discount threshold to 100 %. `Staff/Support/RoleDefinitions.php:28-29`; resources without `canAccess`: Item, Category, ModifierGroup, PromoCode, Till, Printer, PrintJob, Station, KitchenRouting, FloorPlan, DiningTable, FloorSection, TillSettingsPage, ReceiptSettingsPage, CashierSessionResource. Fix: add `canAccess()` per resource keyed to the right permission.
**M7.** A till user works in their *home* branch rather than the till's branch; a branch-B user on a branch-A till books sales to B. `app/Support/Tenancy/SetTenantFromUser.php:21`. Fix: set branch from the till; reject mismatched users.
**M8.** `.env`, `.git` and `storage/` are baked into the Docker image (no `.dockerignore`), so a copied image leaks production secrets. `docker/Dockerfile:40`. Fix: add `.dockerignore`.

## Low

**L1.** Excel exports don't neutralise cells starting with `= + - @`; a staff or item named `=HYPERLINK(...)` becomes a live formula on the owner's laptop. `config/excel.php`, `Staff/Exports/*`, `Reports/Exports/TableExport.php`. Fix: custom value binder that prefixes with `'`.
**L2.** SVG allowed as menu/logo image → stored script on the app's own origin. `Menu/Filament/.../ItemResource.php:88`, `CategoryResource.php:62`, `ReceiptSettingsPage.php:68`. Fix: accept only png/jpeg/webp.
**L3.** Refunds ignore day close, so a closed day's figures can change after the Z-report. `PaymentService.php:124-168`. Fix: post refunds to the current open day.
**L4.** Manager override doesn't check branch; PIN lockout is per IP, so tills behind one router share one lockout (one bad actor can lock all tills). `Staff/Services/PinAuthenticator.php:69`, `AsksForManagerPin.php:40`. Fix: compare branch; key the limiter on the till identity.
**L5.** nginx advertises its version; bind address defaults to all interfaces if `.env` is hand-made. `docker/nginx/default.conf`, `docker-compose.yml:33`. Fix: `server_tokens off;` make `RMS_BIND_IP` required.

## What is solid (confirmed)

- Every route except the start page, clock-in and PIN login is behind authentication; `/admin` and `/hq` are gated by role; inactive users are rejected everywhere.
- PINs are hashed, looked up per tenant, rate-limited (5 tries → 60 s lockout).
- No SQL injection: every raw query uses bound parameters; no user-controlled sort columns.
- No shell injection: printer queue names are allow-listed, all processes use argument arrays, backups use `MYSQL_PWD` not command-line passwords.
- No XSS: zero unescaped output in templates; PDF generation has remote content and PHP disabled; ESC/POS output strips control characters.
- Money is integer pesewas; totals, prices, taxes and service charge are recomputed server-side from the database; tax maths verified by hand (GHS 12.00 exclusive → GHS 14.40; GHS 10.00 inclusive reconciles exactly) and covered by tests.
- Overpay/underpay, paying twice (sequentially), paying voided orders, voiding paid orders, refunds without manager, cash-up variance, day close with open tills/orders — all correctly blocked.
- Secrets: none committed; installer generates keys with `openssl`; only port 80 is exposed from Docker; CUPS admin not exposed; imports stored on a private disk; uploads path-safe.
- Audit trail on orders, payments, cashier sessions, cash movements, day closes, time entries.

## Recommended fix order

1. C1 + H1 + L4 together (same file family) — one session.
2. H2 + H3 + M8 + L5 together (backup/Docker) — one session.
3. H4 + M7 (tenancy/branch) — one session.
4. H5 + M1 + M2 + M3 + M4 + M5 (money/fraud) — one or two sessions.
5. M6 + L1 + L2 + L3 — one session.
Then re-run this review (or the Layer-2 hygiene prompt) and the full test suite before go-live.
