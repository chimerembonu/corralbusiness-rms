# CorralBusiness – RMS — Role access review

Date: 4 November 2026 · First tested at `1692623`; **updated the same day with the owner's decisions** (see "Decisions applied" below).
Method: an automated test signed in as each of the 8 roles and (1) opened every one of the app's 90 pages, and (2) pressed the 11 most sensitive buttons on the till, kitchen and stock screens, each in a fresh app. That is 720 page checks and 88 button checks. Results were compared against what each role is meant to do (CLAUDE.md §3.10 and `RoleDefinitions.php`). Both tests are now part of the automated suite, so a future change that gives a role too much access will fail the build.

## Verdict

**No role can reach anything reserved for a more senior role.** Waiter, Cashier, Kitchen and Rider cannot open the back office at all; only the Owner can open `/hq`; voids, refunds, large discounts, day close and training mode all still need a manager PIN (verified in the earlier security reviews). One small leak was found (staff-sales export, below), plus five design questions for you to decide.

## What each role can do (as tested)

| | Owner | Manager | Cashier | Waiter | Kitchen | Rider | Inventory | Accountant |
|---|---|---|---|---|---|---|---|---|
| **Sign in at a till** | ✓ | ✓ | ✓ | ✓ | ✓ → kitchen screen | ✓ → rider screen | ✓ | ✗ |
| Take orders, add items | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| Discount or promo code up to the threshold (10 %) | ✓ | ✓ | ✓ | PIN | ✗ | ✗ | ✗ | ✗ |
| Discount above threshold, void, refund | ✓ | ✓ | PIN | PIN | ✗ | ✗ | ✗ | ✗ |
| Take payment | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Open a till, pay in/out | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Cash screen, today's sales, X report | ✓ | ✓ | ✓ (no X/Today) | ✗ | ✗ | ✗ | ✗ | ✗ |
| Order list, deliveries board | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| Customer accounts at till | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Kitchen screen: view | ✓ | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ | ✗ |
| Kitchen screen: bump/recall | ✓ | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ | ✗ |
| Rider screen | ✓ | ✗ | ✗ | ✗ | ✗ | ✓ | ✗ | ✗ |
| Stock-take screen | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✓ | ✗ |
| **Back office (`/admin`)** | ✓ all | ✓ all | ✗ | ✗ | ✗ | ✗ | stock only | reports only |
| Menu, prices, promo codes, tables, printers, tills, staff, settings | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Ingredients, suppliers, purchases, stock movements | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✓ | ✗ |
| Sales/item/tax/voids reports, staff sales, cashier sessions, day closes | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ |
| Export reports to Excel/PDF | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ |
| Backups: download | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Backups: restore/delete | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Security log (failed sign-ins and PINs) | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| **Head office (`/hq`)** | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |

"PIN" = the till asks for a manager's PIN before it happens.
The full page-by-page list is `tests/Fixtures/role-access.json`.

## Finding — fixed

**L-R1 (Low). Staff-sales Excel export ignored the export permission.** The Inventory role could download every staff member's sales totals. **Fixed:** the Export button now needs "export reports", like every other report (`StaffSalesPage.php`), and the test that proves it is switched on.

## Decisions applied (owner, 4 November 2026)

| # | Question | Decision | What changed |
|---|---|---|---|
| 1 | Should the storekeeper see sales and cash? | **No** | Inventory role no longer has "view reports": no cash screen, today's sales, X report, sales/tax/voids/staff-sales reports, cashier sessions or day closes, and no sales tiles on the back-office home page. Stock screens unchanged. |
| 2 | Should waiters discount without a manager? | **No** | New permission "small discounts without a manager" given to Owner, Manager and Cashier only. **Every** waiter discount or promo code now opens the manager keypad; the approving manager is recorded. The till tells waiters "Every discount you give needs a manager PIN." |
| 3 | Should waiters and cashiers see the kitchen screen? | **No** | Kitchen screen is for Kitchen, Manager and Owner. The Kitchen button no longer shows on the till for waiters and cashiers. |
| 4 | Receipts by link | **As recommended** | No change. |
| 5 | Accountant till login | **Not needed** | No change. |

Also fixed while doing this: **role changes did not reach an installed store server** (updates only updated the database structure, not who-can-do-what). The server now re-applies the roles every time it starts (`docker/entrypoint.sh`), so these decisions — and any future ones — arrive with the next update.

## Added by the Complete Edition review (8 November 2026, SECURITY-REVIEW-3.md)

- **Owner only:** Till settings → Security (idle locks, two managers for big refunds and day close), the customer retention period, and giving someone the owner or manager role (or resetting another manager's PIN). Managers see these greyed out (confirmed by the owner, 8 November 2026).
- Recipes and extra recipes: Owner, Manager, Inventory. Food cost (sales and margins): Owner, Manager, Accountant. Recipe usage (quantities and stock cost only): Owner, Manager, Inventory, Accountant.
- Two-step sign-in (when switched on): Owner, Manager and **Accountant** (owner's decision, 8 November 2026).
- **Stock costing** (how stock is valued): Owner and **Accountant** change it, Managers can look (owner's decision, 8 November 2026).
- New guard `tests/Feature/BackOfficeActionMatrixTest.php`: every role presses the 9 sensitive back-office buttons of the Complete Edition.

## How to check this yourself at the restaurant (10 minutes)

1. Create one test staff member per role in **Back office → Staff** (or use the demo PINs).
2. For each one, sign in at a till with their PIN and try: open the back office (`/admin`), void a sent item, give a 5 % discount (a waiter should get the manager keypad), take a payment, open the cash screen, open the kitchen screen.
3. Compare with the table above. Anything different, note it and give it to Claude Code with the Fix-it prompt.
4. Delete the test staff afterwards.

## The automated guard (now permanent)

- `tests/Feature/RoleAccessMatrixTest.php` signs in as all 8 roles and opens every page; it fails if any role can open a page not on the approved list, is kept out of one that is, or if a **new page** is added without deciding who may open it.
- `tests/Feature/RoleActionMatrixTest.php` has every role press the 12 sensitive buttons (now including promo codes) and fails if an unapproved role succeeds; it also proves a waiter's discount goes through only with a manager's PIN (the waiter's own PIN doesn't count).
- Proven to work: temporarily giving Waiters back-office access made the page guard fail on all 8 newly opened pages.
- To approve a deliberate change (e.g. after answering question 1), Claude Code re-runs the page guard with `ROLE_ACCESS_WRITE=1`, and you review the changed lines in `tests/Fixtures/role-access.json` before committing.
- Full suite after the decisions: **433 passed**, none skipped.
