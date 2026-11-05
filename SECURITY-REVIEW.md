# CorralBusiness – RMS — Security review, second pass

Date: 2 November 2026 (evening) · Commit reviewed: `5bf00bd` on `main` · Fixes: `addcd24` … `98e6473`
Method: the same as the first pass — three independent read-only reviewers (access control & tenant isolation; injection, files & infrastructure; money & fraud resistance). Each reviewer first re-checked the fixes for the first pass's findings at `5bf00bd`, then hunted for anything new, including regressions caused by those fixes. Every finding below was confirmed by reading the code path end to end; four were additionally proven with throw-away tests. Each fix carries a Pest test. The full suite passed after every commit (315 tests, 2 714 assertions at `98e6473`).

## Scorecard

| | First pass (`bffc8d7`) | This pass (`5bf00bd`) | Fixed |
|---|---|---|---|
| Critical | 1 | 0 | — |
| High | 5 | 2 | 2 |
| Medium | 8 | 11 | 11 |
| Low | 5 | 12 | 10 (2 deferred, see below) |

**First pass: all 19 items confirmed closed** at `5bf00bd`, with one regression (R1 below) that the L4 fix had introduced and that is now fixed.

**Rating: FIT FOR PILOT.** Nothing open touches money, approvals or the single-branch restaurant going live on 13 November. The two deferred items are a product feature (idle lock on a shared till) and multi-branch scoping of back-office lists, which only matters once a second branch exists.

## High — fixed

**R1. Sign-in lockout could be dodged by the browser.** The PIN screen keyed its 5-tries lockout on a public Livewire property (`tillId`), so a browser could send a fresh value with every attempt and try all 10 000 PINs. (Regression from the first pass's L4 fix.) *Fix:* the property is `#[Locked]`; the till cookie it falls back to is encrypted. `addcd24`.

**R2. Making a line smaller after the bill was printed needed no manager.** The first pass stopped *removing* a line and *cancelling* after a bill was printed (H5), but the "−" button still took 10 × Coca-Cola down to 1 × with no PIN and no trace in any report. *Fix:* reducing a quantity after the bill needs the void permission (manager keypad on the till), is logged with the approver, and appears in the voids report under "Quantities reduced after the bill". Increasing never asks. `b983236`.

## Medium — fixed

**R3. Split, merge and cancel lost their guards.** Lines split off or merged in landed on an order without the bill-printed flag; a partly paid order could be split, merged or cancelled leaving its payment dangling; a real table could be merged into a practice one. *Fix:* the flag travels with the lines; an order with a payment on it cannot be split, merged or cancelled (refund first, which needs a manager and is logged); practice and real orders cannot be merged. `f39243e`.

**R4. A fixed discount became a bigger one when the order shrank.** GH₵ 10 off GH₵ 100 (10 %, no PIN) stayed GH₵ 10 off when lines were removed until the order was GH₵ 10 — free. A promo code on top of line discounts slipped past the threshold, and two tills could both claim the last use of a code. *Fix:* unapproved fixed discounts are taken off again when the order shrinks past the threshold (approved ones and percentages stay); a promo code is refused on top of line discounts that together pass the threshold; the use counter is claimed atomically. `294a3ff`.

**R5. The pay-out limit could be split into small pay-outs.** *Fix:* the limit covers everything paid out of the session so far plus the new one. `a255c52`.

**R6. A payment or refund could land in no drawer.** "Open the till first" was checked only when the payment screen loaded; a screen left open after cash-up still took a payment, and a refund never checked. *Fix:* the till must be open at the moment of paying and of refunding. `a255c52`.

**R7. Negative amounts, paid orders and the account ledger.** Negative amounts and tips were stopped only by the database; the delivery fee (and so the total) of a *paid* order could still be changed; a practice order could be put on a real customer's account; refunding an on-account payment left the customer still owing it. *Fix:* all four closed in the services. `4182325`.

**R8. The by-payment-method report moved after a later refund.** It keyed refunds on payment status instead of the day the refund was given. *Fix:* keyed on `refund_business_date` like the other reports (first pass L3). `4182325`.

**R9. The till's "new customer" form passed every field through.** A browser could add `allow_account: true` and then "pay" orders on that customer's account. *Fix:* only name, phone and address are taken from the till; account permission and credit limit are back-office settings. `6034c66`.

**R10. `orders.create` was never enforced.** Riders and kitchen staff could open order screens and add, remove or cancel. *Fix:* riders and kitchen staff are sent to their own screens (also at sign-in); stock counters keep the home screen but cannot start or resume an order; an order URL gives 403. `6034c66`.

**R11. Training mode was a replayable cookie.** Once any device had been in training mode, a copy of that cookie switched any till into it. *Fix:* the state lives on the till row, needs a device that has chosen a till, and is cleared on every till at day close. `6034c66`.

**R12. A manager could reset the owner's PIN or delete the owner.** *Fix:* owner accounts can only be edited, deleted or re-PINned by an owner. `6034c66`.

**R13. Ids sent to till actions were looked up across the whole restaurant.** Opening, reprinting or refunding an order, resuming one, the deliveries board, cash-up reprints and "open till" accepted any id of the tenant; the kitchen screens' branch was an unlocked property; the till and kitchen gates did not re-run on Livewire requests. *Fix:* all of those look up in this branch only, the kitchen branch is `#[Locked]`, and the gate middleware is Livewire-persistent (deactivating someone mid-shift stops their open screen). Deferred part: see D2. `6034c66`.

**R14. Stored JavaScript through void and discount reasons.** A reason saved in Till settings was pasted raw into a `wire:click` expression; a quote in it ended the string and the rest ran as JavaScript on every cashier's screen. *Fix:* reasons are emitted as JavaScript literals (`@js`). `98e6473`.

## Low — fixed

**R15.** An SVG inside a restored backup could be planted under the app's own address. *Fix:* backups may only carry png/jpg/jpeg/gif/webp. `98e6473`.
**R16.** Imported spreadsheets (staff PINs included) stayed on the server and the preview echoed every PIN. *Fix:* the file is deleted once read; the preview says "PIN set". `98e6473`.
**R17.** The queue, scheduler and Reverb containers ran as root, so a nightly backup or a log file they created could block php-fpm. *Fix:* they run as `www-data`; the image creates `storage/backups` owned by `www-data`. The installer now stops with a message instead of binding to every interface when it cannot find the LAN address. `98e6473`. *Check on the mini PC:* the CUPS socket (`/run/cups/cups.sock`) must be writable by `www-data` for USB printing from the queue container; Ubuntu's default (mode 666) is.

Also folded into the commits above: promo codes stacked on line discounts (R4), merging practice with real tables (R3), negative tips (R7), the paid-order delivery fee (R7), account ledger on refund and on practice orders (R7), by-method report (R8), atomic promo uses (R4).

## Deferred (not needed for the single-branch pilot)

**D1. No idle lock on a shared till.** A cashier who walks away leaves the till signed in for up to 12 hours. This is a product feature (configurable idle timeout + auto sign-out in the till layout); scheduled for the first fortnight after go-live.
**D2. Back-office lists and the kitchen channel are tenant-wide, not branch-wide.** Cashier sessions, day closes, stock takes, purchases and stock movements in `/admin` show every branch of the restaurant; the kitchen broadcast channel checks the tenant, not the branch (its payload is only ticket id/number/status). Harmless with one branch; fix with `forBranch()` on those `getEloquentQuery()`s before a second branch opens. *Fixed 5 Nov 2026 on `complete-edition` (E3.1): every back-office list of branch records extends `App\Support\Filament\BranchResource` (guarded by `tests/Feature/BranchSeparationTest.php`), form drop-downs and sub-lists are limited to the branch, the printer and branch-price forms lost their branch picker, and the kitchen channel admits only staff of that branch (or restaurant-wide users of the same restaurant).*
**D3. On-account receipts taken in the back office have no cashier session.** Money received against a customer account from `/admin` lands in no drawer. Either take account receipts only at a till, or add a "received by" drawer choice to the back-office form. Post go-live. *Fixed 5 Nov 2026 on `complete-edition` (E9.5).* Cash needs an open till's drawer, chosen on the back-office form; with no open till, cash is refused (Mobile Money and card need no drawer).

## What is solid (confirmed again at `5bf00bd`)

- Every route except the start page, clock-in and PIN sign-in is behind authentication; `/admin` and `/hq` are gated by role; inactive users are rejected everywhere; every back-office resource and page has `canAccess()`.
- PINs are hashed, looked up per tenant, rate-limited; manager overrides check the permission, the branch and are keyed per till; every `*Approved` step is protected and the approval state is locked.
- Records opened by URL are checked against tenant and branch (`Tenancy::assertOwns`); the tenant scope applies before route-model binding.
- No SQL injection (bound parameters everywhere, no user-controlled sort columns); no shell injection (allow-listed printer queues, argument arrays, `MYSQL_PWD`); no unescaped Blade output; dompdf has remote content and PHP off; ESC/POS output strips control bytes; exports go through a safe value binder; uploads are content-type checked and stored under a content-derived extension; backups are name-checked, entry-checked and extracted from a vetted list; nginx hides its version and refuses PHP under `/storage`.
- Money is integer pesewas, recomputed server-side from the database on every change; overpay/underpay, double payment, double send, double stock deduction, paying voided orders, voiding paid orders, refunds without a manager, refunds on a closed day, cash-up variance and day close with open tills/orders are all refused; the Z report is frozen at close and a closed day's figures no longer move.
- Secrets are generated at install and never committed; the Docker image excludes `.env`, `.git` and local data; only port 80 is published, bound to the LAN address.
- Audit trail on orders, lines, payments, cash sessions and movements, day closes, stock movements, time entries, settings and every manager override.

## Next review

Run this review again after the first week of live trading, with the day's real data in front of the reviewers, and once D1–D3 land.
