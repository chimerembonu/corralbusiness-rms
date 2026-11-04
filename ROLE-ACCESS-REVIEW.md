# CorralBusiness – RMS — Role access review

Date: 4 November 2026 · Commit tested: `1692623` on `main`
Method: an automated test signed in as each of the 8 roles and (1) opened every one of the app's 90 pages, and (2) pressed the 11 most sensitive buttons on the till, kitchen and stock screens, each in a fresh app. That is 720 page checks and 88 button checks. Results were compared against what each role is meant to do (CLAUDE.md §3.10 and `RoleDefinitions.php`). Both tests are now part of the automated suite, so a future change that gives a role too much access will fail the build.

## Verdict

**No role can reach anything reserved for a more senior role.** Waiter, Cashier, Kitchen and Rider cannot open the back office at all; only the Owner can open `/hq`; voids, refunds, large discounts, day close and training mode all still need a manager PIN (verified in the earlier security reviews). One small leak was found (staff-sales export, below), plus five design questions for you to decide.

## What each role can do (as tested)

| | Owner | Manager | Cashier | Waiter | Kitchen | Rider | Inventory | Accountant |
|---|---|---|---|---|---|---|---|---|
| **Sign in at a till** | ✓ | ✓ | ✓ | ✓ | ✓ → kitchen screen | ✓ → rider screen | ✓ | ✗ |
| Take orders, add items | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| Discount up to the threshold (10 %) | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| Discount above threshold, void, refund | ✓ | ✓ | PIN | PIN | ✗ | ✗ | ✗ | ✗ |
| Take payment | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Open a till, pay in/out | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Cash screen, today's sales, X report | ✓ | ✓ | ✓ (no X/Today) | ✗ | ✗ | ✗ | ✓ (view) | ✗ |
| Order list, deliveries board | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| Customer accounts at till | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Kitchen screen: view | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| Kitchen screen: bump/recall | ✓ | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ | ✗ |
| Rider screen | ✓ | ✗ | ✗ | ✗ | ✗ | ✓ | ✗ | ✗ |
| Stock-take screen | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✓ | ✗ |
| **Back office (`/admin`)** | ✓ all | ✓ all | ✗ | ✗ | ✗ | ✗ | stock + reports | reports only |
| Menu, prices, promo codes, tables, printers, tills, staff, settings | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Ingredients, suppliers, purchases, stock movements | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✓ | ✗ |
| Sales/item/tax/voids reports, cashier sessions, day closes | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✓ (view) | ✓ |
| Export reports to Excel/PDF | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ |
| Backups: download | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Backups: restore/delete | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| **Head office (`/hq`)** | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |

"PIN" = the till asks for a manager's PIN before it happens.
The full page-by-page list is `tests/Fixtures/role-access.json`.

## Finding

**L-R1 (Low). Staff-sales Excel export ignores the export permission.** Every report page hides its Excel/PDF buttons unless the role has "export reports". The *Staff sales* page shows its Export button to anyone who can see the page, so the **Inventory** role can download every staff member's sales totals. `app/Modules/Staff/Filament/Pages/StaffSalesPage.php:83`. Fix: add `->visible(fn () => auth()->user()->can(Permission::ReportsExport->value))` (and the same check inside the action), then remove the `->skip(...)` on the staff-sales test in `tests/Feature/RoleActionMatrixTest.php`.

## Questions for you (design choices, not bugs)

1. **Should the Inventory role see sales and cash?** Today the storekeeper can open the till's cash screen (read-only), today's sales, the X report, cashier sessions, day closes and all sales reports, because the role was given "view reports" so it could see stock reports. If a storekeeper shouldn't see takings, remove `ReportsView` from Inventory in `RoleDefinitions.php` and give the stock pages their own check.
2. **Should waiters be able to discount at all?** Waiters can give up to the threshold (default 10 %) with no PIN. It is recorded under their name in the discounts report. If you want every waiter discount approved, set the waiter threshold to 0 or remove discounts from the waiter role.
3. **Should waiters and cashiers see the kitchen screen?** They can look (to know when food is ready) but cannot bump tickets. Common in restaurants; say if you'd rather they didn't.
4. **Receipt by link.** Any role that signs in at a till can open a receipt if they have its link (a long random ID). Riders legitimately need this for deliveries; kitchen and inventory don't, but can't find the links without the order list, which they're blocked from. Low risk; can be tightened later.
5. **Accountant has no till login.** By design: they work only in the back office. Say if your accountant needs to see the till's cash screen.

## How to check this yourself at the restaurant (10 minutes)

1. Create one test staff member per role in **Back office → Staff** (or use the demo PINs).
2. For each one, sign in at a till with their PIN and try: open the back office (`/admin`), void a sent item, give a 20 % discount, take a payment, open the cash screen, bump a kitchen ticket.
3. Compare with the table above. Anything different, note it and give it to Claude Code with the Fix-it prompt.
4. Delete the test staff afterwards.

## The automated guard (now permanent)

- `tests/Feature/RoleAccessMatrixTest.php` signs in as all 8 roles and opens every page; it fails if any role can open a page not on the approved list, is kept out of one that is, or if a **new page** is added without deciding who may open it.
- `tests/Feature/RoleActionMatrixTest.php` has every role press the 11 sensitive buttons and fails if an unapproved role succeeds.
- Proven to work: temporarily giving Waiters back-office access made the page guard fail on all 8 newly opened pages.
- To approve a deliberate change (e.g. after answering question 1), Claude Code re-runs the page guard with `ROLE_ACCESS_WRITE=1`, and you review the changed lines in `tests/Fixtures/role-access.json` before committing.
- Full suite at this commit: **419 passed**, 4 skipped (the staff-sales check, until L-R1 is fixed).
