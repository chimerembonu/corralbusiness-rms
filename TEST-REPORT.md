# CorralBusiness – RMS — Independent test report

Date: 9 November 2026 · Commit tested: `38d770e` on `main` (the "one version" merge) · Go-live: 13 November 2026

## How it was tested

Seven independent checks, each run by a tester who had not written the code and was told to break it, not to confirm it:

| # | Check | What was done |
|---|---|---|
| 1 | **Automated tests** | The project's own 655 tests, on SQLite (as CI does) and again on **MySQL 8** (what the restaurant runs). Code style (pint), PHP syntax on 717 files, leftover debug code, npm and Composer vulnerability audits. |
| 2 | **Real-browser walk-through** | A robot browser (Chromium) used the app like staff would, at till size (1024×768) and tablet size: sign in with PINs, open a till, take dine-in, takeaway and delivery orders, send to kitchen, bump on the kitchen screen, discounts, voids, split bills, cash, MoMo (demo), refunds, cash-up, day close, back office, reports, exports. Screenshots of every step. |
| 3 | **Live attack** | A tester attacked the running app from outside: about 73 attempts (PIN guessing, other restaurants' records by ID, forged forms, hidden files, setup-wizard takeover, licence forgery, tampering with the history log, oversized and malicious inputs). |
| 4 | **Code review — access & licence** | Every sign-in path, panel gate, role, two-step sign-in and licence check read end to end. |
| 5 | **Code review — money** | Taxes, totals, discounts, splits, tips, refunds, cash-up, Z/X reports, stock deduction and costing traced and recomputed by hand. |
| 6 | **Speed, accuracy & usability** | 6 tills ordering at once, a year of sales in the reports, the kitchen screen with the queue worker stopped; touch-target sizes, contrast and an automated accessibility scan (axe). |
| 7 | **Installation & recovery** | The Linux installer run on a clean machine; database built from nothing on MySQL; demo data loaded; **backup → damage the database → restore → check** drill, including a corrupted backup. |

## Scorecard

| Area | Rating | In one line |
|---|---|---|
| Features work as intended | **Good, with fixes** | Every main flow works end to end; 9 bugs found that staff would notice (split evenly, opening float, reports counting removed items, voided tickets left on the kitchen screen…). |
| Security | **Good, one serious gap** | 70 of 73 live attacks blocked, none got in. But a code review found that an owner's or manager's **till PIN opens the back office with no password and no two-step code** (H1). |
| Money accuracy | **Very good** | Taxes, totals, Z/X reports and stock agree to the pesewa in every worked example; 3 report bugs and 2 payment-provider gaps (H2–H4) to fix. |
| Speed | **Good for a normal day** | Till screens fast; long report ranges run out of memory; 6 tills at once need MySQL (as installed), not SQLite. |
| Ease of use / accessibility | **Fair** | Works, but some buttons are smaller than the 48 px rule and two header buttons fall off the screen at 1024 px. |
| Installation & recovery | **Good, with fixes** | Installs and restores correctly; a **damaged** backup leaves the database half-restored (H11). |
| Third-party packages | **Clean** | npm: 0 known vulnerabilities. Composer audit could not reach its server from the test machine — run it once on the Mac (`composer audit`). |

**Overall: fit for the 13 November pilot once the 11 High items below are fixed** (or, for H6/H9/H10, worked around on the day — see "If there is no time"). None of the Highs lets an outsider in; they are about staff shortcuts, money shown wrongly, and recovery.

## Findings

Each finding was confirmed by a test, a screenshot or by reading the code end to end. "Where" is for the developer (Claude Code).

### High — fix before 13 November

| # | What happens (plain English) | Where | Fix |
|---|---|---|---|
| **H1** | An owner or manager who signs in **at a till with their PIN** can then type `/admin` and use the whole back office (staff, prices, settings, backups) **without the password or the two-step code**. Anyone who sees a manager's 4-digit PIN gets the back office. *Confirmed with a test: `/admin` and `/admin/staff` answer 200 after a PIN sign-in.* | `Pos/Livewire/PinLogin.php` (~103) logs in on the same `web` guard; `Support/Filament/RequireTwoStepSignIn.php` only checks that 2FA is *set up*, not that it was *passed* this session. | Mark PIN sign-ins as till sessions (`session(['signed_in_by' => 'pin'])`) and have the panels refuse them (send to the password page), and make the two-step check require a per-session "passed" flag. Add both to `AdminAccessTest`. |
| **H2** | Paystack: if sending a MoMo prompt **times out** or Paystack answers 5xx, the app marks it *failed* and never checks again — but Paystack may already have sent the prompt. The cashier tries again and the customer can pay **twice**, with the first payment never recorded. | `GatewayPaymentService::call()` returns *failed* for any error while sending. | On a time-out or 5xx while sending, return **Pending** and let the status check decide (exactly what `docs/ADDING-A-PAYMENT-PROVIDER.md` already says adapters must do). Test with `Http::fake` throwing `ConnectionException`. |
| **H3** | Paystack refunds are not protected against repeats: a refund retried after a time-out can be paid out twice, or retried forever. | Paystack adapter `refund()` / `refundStatus()` | Send our refund reference, look it up before re-sending, stop after a fixed number of tries and mark "needs a manager". |
| **H4** | The **receipt web page** (the link/QR receipt) leaves out the customer's TIN, which the printed receipt shows. GRA needs it on a VAT invoice. | `Pos/views/receipt.blade.php` | Add the TIN line as on the printed receipt; test. |
| **H5** | **Split evenly** gives wrong shares: a GH₵ 65.90 bill split three ways asked for 32.95, then 21.96, then 14.64 (it splits what is *left* by the *original* number). | Split-bill "equal shares" calculation | Share = remaining ÷ remaining people, last share takes the pesewa remainder; test 2-, 3- and 7-way splits. |
| **H6** | **Opening float**: typing digits on the keypad is ignored; the box stays at the pre-filled 500. A cashier who starts with GH₵ 300 has a GH₵ 200 "shortage" at cash-up. | Open-till keypad | Clear the pre-filled value on first key press (or bind the keypad to the field); browser test. |
| **H7** | **Tax, item and category reports count items that were removed from an order** before payment. Tax due is over-stated. | `Reports/Services/ReportService.php:123` and `FoodCostService.php:32` use `withoutGlobalScopes()`, which also drops the "deleted" filter. | Add `->whereNull('deleted_at')` (or `withoutGlobalScope(TenantScope)` only); test that a removed line is not in the tax report. |
| **H8** | A **voided order's tickets stay on the kitchen screen**, so the kitchen may cook food nobody will pay for. | No listener for `OrderVoided` in Kitchen | Listener that cancels the order's open tickets and broadcasts; test. |
| **H9** | **Void fails when an item's stock is below zero**, so the stock is not put back (and the void errors). Busy kitchens often go below zero before a delivery is entered. | Stock return on void | Allow returning stock whatever the current level; test with negative stock. |
| **H10** | **Long report ranges run out of memory** (a year of sales). The page fails. | Reports load every line into memory | Sum in the database (`SUM … GROUP BY`) or process in chunks; test with a year of seeded orders. |
| **H11** | **A damaged backup half-restores.** In the drill, a backup with a few corrupted bytes was accepted, the restore stopped part-way with a database error, and the live database was left with **no customers and no orders**. The automatic "state before restore" copy did bring everything back — but only if someone knows to restore it. | `Settings/Services/BackupService::restore()` | Store a SHA-256 of `database.sql` in `manifest.json` and refuse a file that does not match **before** touching the database; if the restore still fails, restore the safety copy automatically and say so. |

### Medium — first two weeks after go-live

| # | What happens | Fix |
|---|---|---|
| M1 | With the queue worker stopped, the kitchen screen can lag up to **28 seconds** (target: 2 s). | Make `TicketChanged` `ShouldBroadcastNow`; the installer already starts the worker, but the KDS should not depend on it. |
| M2 | 6 tills placing orders at the same instant hit "database is locked" on **SQLite**. The restaurant runs MySQL, which handles this far better, but the six-till rush is on the on-site list (item 8). When a lock does happen the till gives no clear message. | Friendly "try again" message on lock errors. |
| M3 | A manager can reach another branch's back office by choosing that branch's till. | Tie back-office branch to the user's branch, not the till cookie (matters only once a 2nd branch opens). |
| M4 | The history check (audit chain) has no secret key: someone with database access could rewrite history and rebuild every check code. | Key the chain with a secret held outside the database (`APP_KEY`-derived HMAC). |
| M5 | The per-device PIN limit counts by IP; on **Docker Desktop** (Mac/Windows box) every till looks like the same IP, so one person's wrong PINs can lock **every** till for a minute. | Count per till cookie + IP, or trust the real client IP from the proxy. |
| M6 | Recipes-vs-stock-take report misses manual stock adjustments. | Include adjustments in "actual usage". |
| M7 | Ingredient cost is stored in **whole pesewas per unit**, so items costed per gram/ml are rounded badly (GH₵ 0.004/g becomes 0). | Store cost per unit with more decimals (e.g. per 1 000 units). |
| M8 | **"Anonymise customer"** leaves personal data: the masked phone keeps 7 of 10 digits, and names/phones remain in refund reasons, payment notes and the history log. | Mask to last 2–3 digits; scrub those fields. (GDPR / Ghana Data Protection Act.) |
| M9 | Typing a bad discount (e.g. letters) **silently removes the discount already on the order**. | Validate and keep the old discount, with a message. |
| M10 | There is **no screen to change tax rates** (they are seeded). If GRA changes a rate, a developer is needed. | Add Settings → Taxes (owner only), audited. |
| M11 | The MoMo **demo** provider needs a licence, so training can't show MoMo on a fresh box. | Allow Demo without a licence (it only runs on training orders). |
| M12 | `/hq` is almost empty, and a manager who tries it gets a confusing message. | Say "Head office is for the owner"; fill `/hq` in Phase 6. |
| M13 | No **86 (sold out)** button on the till (only in the back office). | Add to the till's item long-press for managers. |
| M14 | At **1024 px wide** the header's **Lock** and **Today** buttons are off-screen. | Wrap or shrink the header. |
| M15 | Every tap on **Takeaway** or **Delivery** creates an **empty order** in the order list. | Create the order on first item added. |
| M16 | Cash-up ignores **refunded tips**, so the expected cash is wrong after a tip refund. | Subtract refunded cash tips. |
| M17 | Orders after midnight are grouped on the calendar date in some reports and on the **business date** in others. | Use business date everywhere. |
| M18 | X report and the sales-by-payment-method report disagree when a refund was given with a different method. | Use the same refund rule in both. |
| M19 | On MySQL the stock-movement history can list movements made in the same second in the wrong order. | Sort by time, then `id`. |

### Low — when convenient

1. If the **licence lapses**, two-step sign-in enforcement and recipe stock deduction silently switch off. Show a banner; keep security features on regardless of licence.
2. Installers download and run without an **integrity check** (checksum/signature).
3. Windows installer leaves `.env` readable by all local users.
4. No **HSTS**, **Content-Security-Policy** or **Referrer-Policy** headers (matters for Corral Cloud, not the LAN box).
5. **Connections** URL fields could be pointed at internal addresses (latent SSRF); allow-list `https://` public hosts.
6. The back office loads staff avatars from **ui-avatars.com** — breaks offline and tells an outside site staff names. Use local initials.
7. A cashier sees a **Z-report** link that leads to "403 forbidden". Hide it.
8. "Already paid" message is unclear when a second till tries to pay the same order.
9. Touch targets of 44, 40 and 21 px exist (rule: 48 px). Contrast and a few axe issues (missing labels).
10. `install.sh` needs the `ip` command and exits silently without it; check and print a message.
11. The test suite writes to and empties the **real** `storage/backups` folder. Point tests at a temporary folder (a developer running tests on the store server would delete its backups).
12. A restore can hang waiting for open sessions on MySQL; stop the app (maintenance mode) before restoring.
13. CI builds the Docker images but never **starts** them; add a job that runs `docker compose up`, migrates and loads `/up`.
14. A failed backup can leave a small `.tmp-*.sql` file behind (cleared by the next backup).
15. Cosmetic: a few labels and spacing issues listed in the browser test notes.

## What was confirmed solid

- **Live attack:** about 73 attempts, about 70 blocked outright; the rest only revealed harmless information. No Critical, High or Medium from outside.
- PIN lockout; other restaurants'/branches' records by ID (IDOR); setup-wizard takeover; licence signature forgery; history (audit) tampering is **detected**; CSRF; no hidden files reachable (`.env`, `.git`, backups); error pages leak nothing.
- Earlier security fixes (C1 manager-PIN bypass, H2 backup download, H3 zip-slip, H4 tenant check) still hold.
- **Money:** every worked example matched to the pesewa. For example: 2 × Jollof @ 60 + Club @ 25, 10 % discount → taxes 26.10, service charge 13.05, total **169.65**, plus GH₵ 10 tip = **179.65**. Weighted average cost: 10 kg @ 20 + 5 kg @ 26 = **22.00**; after selling 6 kg, + 9 kg @ 25 = **23.50**. Stock deducted once per line; Z and X reports agree (except M18).
- **Roles:** every role can do exactly what ROLE-ACCESS-REVIEW.md says (apart from H1).
- **Backups:** backups are locked (AES-256); a good backup restores fully in about 4 seconds after deleting the menu, dropping customer tables and renaming every user; the history check passes after restore.
- **MySQL:** the database builds from nothing in about 10 seconds; demo data loads.
- **Code health:** style check clean, no syntax errors, no leftover debug code, npm 0 vulnerabilities.

## MySQL test run

The project's tests normally run on SQLite. They were run again on **MySQL 8.0** (the restaurant's database): **640 passed, 7 failed** (restore tests run separately; 22 minutes).

None of the 7 is a fault the restaurant would meet, except the first, which is small:

| Failure | Cause | Real problem? |
|---|---|---|
| 2 stock tests | The stock history is sorted by time only; two movements in the same second come back in any order on MySQL. | **Yes, minor (M19):** the stock-movement list can show same-second lines in the wrong order. Fix: also sort by `id` (UUIDv7 ids are in time order). |
| Outbox, menu availability, cash-up count | MySQL stores JSON with its keys re-ordered; the tests compare key order. | No — the values are identical. Fix the tests (`toEqual`). |
| Backup is locked | MySQL's backup tool can't see data a test hasn't saved yet. | No — the drill above proved backups are locked and complete. |
| Backup to USB folder | The test waited on a database lock held by the test itself (same cause as Low 12) and was stopped after 18 minutes. | No for the app; the tests that drop tables should not run inside a test transaction. |

**Add a MySQL job to CI** so these differences are caught on every change, not only in a review.

## What can only be tested at the restaurant (before the doors open)

These need the real hardware; tick each one on the mini PC with the restaurant's own devices.

1. **Receipt printers:** print a receipt from every till; check the logo, all three taxes, the TIN line, and that 80 mm (or 58 mm) paper is not cut off.
2. **Kitchen printers / screens:** send an order with grill, fry and bar items; each station gets only its items, within 2 seconds.
3. **Cash drawer:** opens on a cash payment, not on MoMo/card.
4. **Unplug a printer** mid-service: the "Printer problems" banner appears and the job prints when it's plugged back.
5. **Wi-Fi:** walk a tablet to the far end of the dining room and the kitchen; take an order there.
6. **Turn the internet off** (unplug the router's internet cable, not the Wi-Fi): take orders, pay, print, close the day.
7. **Pull the mini PC's power** during service, power on: everything comes back, no order lost.
8. **Six tills at once** at the lunch rush with real staff.
9. **Real MoMo** with Paystack/PaySwitch in **test mode** first, then one real GH₵ 1 payment and refund.
10. **Backup to USB** and a restore on a spare machine (this is the drill above, on the real box).
11. Each role's 10-minute check from ROLE-ACCESS-REVIEW.md.

## If there is no time to fix everything before 13 November

- **Must fix:** H1 (back office by PIN), H2 (double MoMo charge), H5 (split evenly), H6 (opening float), H7 (tax report), H11 (bad backup).
- **Can work around on day one:** H8 — kitchen bumps voided tickets by hand; H9 — enter the delivery before voiding; H10 — run reports one month at a time; H3/H4 — don't use Paystack refunds or receipt links in week one; give a printed receipt for VAT invoices.

## Things not tested here

Real printers and cash drawer, Reverb across several machines, real Paystack/PaySwitch accounts, the Docker stack actually running (the test machine could not download Docker images; the image build itself passes on GitHub), the actual mini PC and Wi-Fi. They are all on the on-site list above.

## Addendum — must-fix items fixed (9 Nov 2026, pull request #3)

| # | Status |
|---|---|
| H1 | **Fixed.** A till PIN no longer opens `/admin` or `/hq`; the back office asks for email, password and (when on) the two-step code. Attempts are in the security log. |
| H2 | **Fixed.** A time-out or Paystack server error while sending a prompt leaves it waiting and checking; a second prompt is refused meanwhile; money that arrives is recorded once. |
| H5 | **Fixed.** Each share = what is left ÷ people still to pay (tested 2, 3 and 7 ways, to the pesewa). |
| H6 | **Fixed**, and the same fault on the **payment** keypad (typing a part amount after choosing Mobile Money or card was ignored). Checked in a real browser. |
| H7 | **Fixed.** Removed lines are out of the tax, item, category and food-cost reports. |
| H11 | **Fixed**, plus Low 14. Damaged backups are refused before anything is touched; a restore that fails part-way is undone automatically. Checked on MySQL 8. |

Full suite after the fixes: **675 passed** (4,660 checks). Still open, with day-one workarounds above: H3, H4, H8, H9, H10.

Also found while fixing: the backup tests share the real `storage/backups` folder, so they fail when the suite is run **in parallel** (`--parallel`); CI runs them one at a time and passes. Same root cause as Low 11.
