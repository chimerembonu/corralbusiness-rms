# CorralBusiness – RMS — Independent security re-review (second pass)

Date: 2 November 2026, evening · Commit reviewed: `0c62f27` on `main` (28 security commits since the first review at `bffc8d7`)
Method: two independent adversarial reviewers, each instructed to attack the fixes rather than trust commit messages; headline items re-verified by hand; full test suite run from a clean environment. Nothing in the repository was modified.

## Scorecard

| Severity | First review (bffc8d7) | Now (0c62f27) |
|---|---|---|
| Critical | 1 | **0** |
| High | 5 | **0** |
| Medium | 8 | **2** |
| Low | 5 | **5** |
| Tests passing | 228 | **315** (2,714 checks) |

**Rating: FIT FOR PILOT.** Every Critical and High from the first review is confirmed closed with a test that proves it. The two remaining Mediums are worth fixing before go-live because they are cheap (one session), but neither lets money disappear silently — both leave a trail in the reports.

## Verified closed (all 19 first-review items + R1–R17 second-pass items)

| Item | Verdict | Evidence |
|---|---|---|
| C1 manager-PIN bypass | Fixed | All 14 `*Approved` methods are `protected`; `$approval` is `#[Locked]`; `approve()` verifies the permission server-side. No other public method accepts a user ID for a privileged action. `Pos/tests/ManagerPinBypassTest.php`. |
| H1 training mode | Fixed | Permission asserted server-side; state lives on the till record, not a cookie. |
| H2 backup download | Fixed | `basename` → strict filename pattern → `realpath` inside backups dir. Defeats `..`, symlinks, URL-encoding, absolute paths. Restore/delete Owner-only. |
| H3 zip-slip | Fixed | Every zip entry validated before extraction (allow-list of three paths, image extensions only); nginx denies `.php` under `/storage`. |
| H4 tenant/branch on URL load | Fixed | Tenant middleware now runs before record lookup; plus explicit ownership assertion in all 7 ID-bearing entry points. `tests/Feature/BranchIsolationTest.php`. |
| H5 remove/cancel after bill | Fixed | Remove, reduce quantity, cancel after a printed bill all need a manager; flag cannot be reset; survives split/merge; shows in voids report. |
| M1 discount stacking | Fixed | Cumulative threshold across line + order + promo; approver stored; re-judged when the order shrinks. |
| M2 double-tap / double stock | Fixed | Row lock + re-read inside the transaction for payment and kitchen send; stock deduction idempotent per line. |
| M4, M5, M6, M7, M8, L1–L5 | Fixed | See tests `DeliveryFeeTest`, `TrainingSplitTest`, `AdminAccessTest`, `TillBranchTest`, `DockerImageHygieneTest`, `ExcelFormulaInjectionTest`, `ImageUploadTypesTest`, `RefundAfterDayCloseTest`, `ManagerPinBranchTest`, `StoreServerHardeningTest`. |
| M3 pay-out limit | **Partial** | See N1 below. |
| R1 lockout key | **Partial** | See N3 below. |
| R10 till permissions | **Partial** | See N2 below. |
| R2–R9, R11–R17 | Fixed | Verified individually; none weakened a previously-confirmed control (overpay cap, paid-order lock, cash-up variance PIN, day-close guards all intact). |

## Remaining findings

### Medium

**N1. Pay-out limit resets every time the till is re-opened.** Plain English: a cashier can pay out the maximum (say GHS 200) with a typed reason, cash up with a perfect count (pay-outs reduce the expected cash, so no variance and no PIN), re-open the same till with the float, and pay out another GHS 200 — repeatable all day. Every movement *is* logged and visible on the Z-report, so it is not invisible, but nothing stops it in the moment. `Payments/Services/CashService.php:131-146, 95-97, 217-224`. Fix: sum pay-outs across all of that till's (or cashier's) sessions for the business day when checking the threshold, or require a manager PIN to open a second session on the same till in one day.

**N2. Order list at `/pos/orders` is open to every till login, including Rider and Kitchen roles.** They can see every order of the branch with customer name, phone and address, and re-print receipts. Refunds remain PIN-gated. `Pos/Livewire/OrdersList.php` (no `mount()` permission check), `Pos/routes.php:35`. Fix: in `mount()`, `abort_unless` the user can create orders or take payments; extend the rider test in `AccessReviewTest.php`.

### Low

**N3. Login lockout multiplied by number of tills.** The lockout is now correctly per till, but the browser may choose which till it is, so an attacker gets 5 tries × 6 tills per minute instead of 5. `Pos/Livewire/PinLogin.php:45`. Fix: add a per-IP ceiling in `PinAuthenticator::attempt`.

**N4. Quantity reductions *before* the bill is printed leave no audit entry.** Ring 3 × Coke, quote the total verbally, take cash, tap "−" twice: no PIN, no activity-log row, no report line. Only reductions after bill print are reported (H5 fix). Fix: log every quantity decrease on a sent line to the activity log and show them in the voids & discounts report.

**N5. Refund double-submit.** `refund()` checks "already refunded" outside the transaction and does not lock the payment row; a double-tap could refund twice (and credit an account customer twice). `Payments/Services/PaymentService.php:131-152`. Fix: `lockForUpdate()` + re-check inside the transaction, exactly as was done for `record()`.

**N6. Kitchen bump/recall and rider assignment look up by tenant, not branch.** A branch-A kitchen user can bump branch-B tickets by ID; a rider from another branch can be assigned. Harmless with one branch; part of the acknowledged D2 deferral. `Kitchen/Livewire/KitchenDisplay.php:59-86`, `ExpoDisplay.php:43`, `Pos/Livewire/DeliveriesBoard.php:38`. Fix: `->forBranch()` on those lookups.

**N7. (Informational)** Backup restore/delete are Owner-only via `->visible()`; this is safe in Filament 4, but `->authorize()` would make the intent explicit and survive a future refactor.

## On the three items the developer deferred

- **D1 (idle lock on a shared till)** — agree, post-go-live product feature. Mitigate on day one by telling staff to tap "Sign out" when leaving a till; put it on the cheat-sheet.
- **D2 (branch scoping of back-office lists and kitchen channel)** — agree; irrelevant until a second branch exists. Must be done before Phase 6 (cloud/HQ).
- **D3 (on-account receipts taken in the back office land in no drawer)** — agree it can wait, but **instruct the restaurant not to receive account payments in the back office until it is fixed**; take them at a till only. Otherwise cash arrives that no cash-up expects.

## Recommended before 13 November

One Claude Code session: N1, N2, N5 (all small). N3, N4, N6 and N7 can go into the first post-go-live week with D1–D3.

## Confirmed solid (unchanged from first review)

Authentication on every screen; hashed, tenant-scoped, rate-limited PINs; no SQL, shell or template injection; money in integer pesewas with server-side recomputation; tax maths verified; secrets absent from repo and image; Docker exposes port 80 only; audit trail on orders, payments, sessions, movements, day closes, time entries — and now also on removed/reduced lines after bill print.

## Addendum — fixes verified at `1ac48e0` (2 Nov 2026, 22:45)

- **N1 fixed.** Pay-out threshold now sums every session on the same till (or cashier) for the business day (`CashService::payOutNeedsManager`).
- **N2 fixed.** `/pos/orders` requires an order-taking or cashier permission; Rider and Kitchen get 403 (`OrdersList::mount`).
- **N5 fixed.** Refund locks the payment row and re-checks "already refunded" inside the transaction (`PaymentService::refund`).
- N3, N4, N6, N7 and D1–D3 are listed in PLAN.md under post-go-live week 1.
- Full suite: **319 tests pass (2,732 checks).**

**Scorecard now: 0 Critical, 0 High, 0 Medium, 4 Low (scheduled). Rating: fit for pilot.**
