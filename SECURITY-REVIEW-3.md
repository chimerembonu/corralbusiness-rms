# CorralBusiness – RMS — Complete Edition review (E9.6): security, roles and compliance

Date: 8 November 2026 · Branch `complete-edition`, reviewed at `ea936df` (25 commits since `main`: E1 licence, setup wizard, features and connections; E2 payment providers; E3.1 branch separation; E5 recipes and costing; E9.1–E9.5).
Method: three independent reviewers, run in parallel, each told to attack the code, not trust it: **security** (anything reachable without signing in, licence, payment providers, sign-in, HTTPS, the tamper-evident history), **roles and branches** (every new page, button and Livewire method against the role rules), and **privacy, money and compliance** (Ghana Data Protection Act 843, VAT, PCI, internal controls). Every finding was proved with a throwaway test before it was reported; each one fixed below has a permanent test.

## Scorecard

| Severity | Found | Fixed now | Left, with reason |
|---|---|---|---|
| Critical | 0 | — | — |
| High | 2 | 2 | 0 |
| Medium | 8 | 8 | 0 |
| Low | 14 | 10 | 4 |
| Tests passing | 570 before | **640 after** (4,505 checks) | |

**Rating: FIT FOR THE COMPLETE EDITION TO BE BROUGHT INTO THE PILOT** (after the pilot's two calm weeks, ROADMAP A12). Nothing critical was found. The two Highs were closed: a forged "details removed" record could hide an edit in the history, and removed customers' details survived in the outbox (a copy the future cloud link will send). Four Lows are left on purpose and listed at the end.

## High — fixed

**H1. A forged "details removed" record could excuse any edit to the tamper-evident history.** *Who:* someone with direct access to the database (or a doctored backup that gets restored). *How:* change a sealed entry (a refund amount, say), then add a fake "customer details removed" entry and a matching removal list. The check passed and old Z-report codes still matched.
**Fixed** (`app/Support/Audit/AuditChain.php`):
- Every entry now has a second fingerprint that leaves out personal details only, and the chain links both. A removal can change names, phones and addresses, but never an amount, a reason or who did it.
- A removal list counts only when all of these hold:
  - it comes from a genuine removal entry, sealed after every entry it lists;
  - it covers customers, addresses or orders only.
- The nightly check now also tests every history code printed on a Z report by itself, so a rebuilt history shows up even if nobody types a code. The one thing it cannot see is now written down in the code and below.

Test: `TamperEvidentLogTest` ("cannot be fooled by a forged removal record").
The chain was sealed again from the start, because the fingerprints changed. This was safe because E9.4 has never run at a restaurant, and your Mac's 566 entries were re-sealed and check clean.

**F1. Removed customers' details stayed in the outbox.** The outbox is the list of events kept for the cloud link (Phase 6). It held the phone (customer attached), the name, phone and address (delivery details) and the name (order held). After "Remove personal details", those copies would have been synced to the cloud later, and never cleaned.
**Fixed:**
- These events now carry ids only.
- Removal blanks the details in older outbox rows.
- A new `customers.anonymised` event tells the cloud to clean its copy too.

Test: `CustomerPrivacyTest` ("leaves no copy of the details anywhere").

## Medium — fixed

- **M1. One manager could switch off the checks on managers.** In Till settings, any manager could switch off "two managers for big refunds / day close" or the idle locks, act alone, then switch them back on.
  - **Fixed:** the Security section is the owner's. Managers see it greyed out with "Only the owner can change these settings", and the server refuses it too, not just the screen.
- **M2. A manager could be both managers.** A manager could create a second manager account, or reset another manager's PIN, and then type both PINs.
  - **Fixed:** only the owner can give the owner or manager role, and edit, delete or reset the PIN of another manager. A manager can still edit their own profile.
- **M3. A manager could wipe customers across all branches overnight.** The customer retention period is restaurant-wide, and any manager could set it to 1 month.
  - **Fixed:** it is the owner's, and it is 0 (keep) or at least 6 months; a smaller number stored by hand is read as 6.
- **M4. The setup wizard gave the backup password to anyone on the network.** It was given before the setup code was typed, and an old wizard page kept working after setup.
  - **Fixed:**
    - the password is shown only on the backup screen, after the code;
    - once the restaurant exists, every button on an old wizard page is refused;
    - a store server without a setup code refuses to open the wizard (`install.sh` now always makes one);
    - wrong codes are limited across all devices too (30 an hour).
- **M5. The automated role check was blind to the recipe pages.** Recipes, Extra recipes, Food cost and Recipe usage were tested with the feature switched off, and two record pages were silently skipped.
  - **Fixed:**
    - the check switches the features on;
    - it creates the missing records;
    - a skipped page is now a failure.
  - Access confirmed against your role rules: Recipes are for Owner, Manager and Inventory. Food cost, which shows sales and margins, is for Owner, Manager and Accountant, never Inventory. Recipe usage shows quantities and stock cost only, no sales, so Inventory may see it.
- **F2. Printed copies kept customers' details for ever.** Receipts in the print queue and kitchen tickets held name, phone and address.
  - **Fixed:** removal cleans them, and finished print jobs are deleted after 30 days each night (`model:prune`, 03:30).
- **F3. Removal missed orders not linked to the customer.** This covered split-off bills and orders where someone typed the phone by hand.
  - **Fixed:**
    - split bills keep the customer link;
    - removal also finds orders carrying the customer's phone number;
    - the export now includes payments, Mobile Money/card checkouts and deleted addresses (Act 843 right of access).
- **F5. Cash given back "by hand" for a failed provider refund was not in the drawer.** Cash-up would come out short with no record.
  - **Fixed:** the till has two buttons.
    - "Gave cash from this till" records a pay-out from that drawer.
    - "Sent by Mobile Money" needs the reference.

## Low — fixed

- **Device setup page:** a tab hidden in the link could send a device to another website. Control characters are now refused.
- **The certificate authority key was readable by the web container.** If someone broke into it, they could have made certificates for any private address. The key now lives in `docker/ca`, never mounted into a container; `https.sh` moves an old one there itself. `--ip` is also checked to be an address.
- **Printer test prints could reach any service on the network.** Only printer ports 9100–9109 are allowed, and printer names are cleaned before printing.
- **Licence:**
  - Every code must now name the restaurant (`--tenant`); `--any-restaurant` exists for demo codes only.
  - Setting the server clock back no longer makes a licence last longer, because it counts from the newest history entry.
  - The Licence page warns when the clock is behind.
- **Payments:** a provider payment in another currency is held for a manager instead of being counted as cedis. Tips count toward "refunds above this need two managers", because they go back too.
- **Card numbers** are refused in the "given back by hand" note (PCI).
- **Error logs:** they no longer show what a function was given, such as a customer's phone or a PIN (`zend.exception_ignore_args`, `#[SensitiveParameter]`).
- **History entries added:**
  - two-step sign-in set up or switched off;
  - backup restored (written into the restored copy) or deleted;
  - loyalty points removed with a customer.
- **The removal dialog** now says that backups of the last 14 days, and USB copies, still hold the old details, and that removal must be done again after restoring an older backup.
- **The back-office action guard** is new (`tests/Feature/BackOfficeActionMatrixTest.php`). All 8 roles press the 8 new sensitive back-office buttons:
  - security settings, retention, features, licence;
  - customer export and removal;
  - giving the manager role, resetting another manager's PIN.

## Left on purpose (Low / Info)

1. **History check across several restaurants on one server.** The chain covers the whole server. On a store server (one restaurant) this is correct. On a shared cloud server, one owner would see another restaurant's problems, so it **must become one chain per restaurant before Corral Cloud hosts several restaurants (E3.2)**. Added to that step.
2. **What the history check cannot see.** Someone with full access to the server can delete the very newest entries (after the last Z report) and reset the end of the chain. The next Z report's code is the safeguard; the code says so.
3. **Customer export and removal by any branch's manager** cover the whole restaurant. That is right for a data-protection request, which is about the person, not a branch. Both are logged.
4. **Smaller items for later stages:**
   - Refunded tips don't yet show in the refunds report (E5.4 accounting export).
   - Gateway refund outcomes and hand-edited ingredient costs are not yet in the outbox (when sync is built, E3).
   - Ingredient cost is stored in whole pesewas per gram or millilitre, which rounds cheap ingredients (a change to cost per 1,000 units, E5 follow-up).
   - A Mobile Money prompt waits up to 20 seconds for the provider at the till. It is bounded and fails safely.
   - PaySwitch's status reply must be checked for the amount once the test account exists (E2.3).
   - Log files are writable by every account on the server, on purpose (root and the app both write them, `c93040f`). On a dedicated store server there are no other users.

## Decisions for you (owner)

1. **Accountant and two-step sign-in.** ✅ *Decided 8 Nov: yes.* With "Two-step sign-in" switched on, the accountant (who can export every sales report) sets it up too, like owners and managers.
2. **Average cost.** Weighted-average costing is still off by default. Ghana follows IFRS (IAS 2), which allows weighted average or first-in-first-out, not "last price paid". **Ask the accountant**; I suggest switching it on for new restaurants.
3. The new owner-only rules (security settings, retention, who becomes a manager) are my recommendation from this review. Tell me if a manager must be able to do any of them.

## Compliance action plan (COMPLIANCE-ASSESSMENT.md section 8), status now

| # | Item | Status |
|---|---|---|
| 1–5 | Card numbers blocked, backups locked, update stops on failed backup, security log, customer TIN | Done (the card check now also covers refund notes) |
| 6–10 | DPC registration, GRA e-VAT question, PCI SAQ B, privacy notice, accountant's confirmations | **Waiting on the restaurant and you** |
| 11 | HTTPS on the store server | Done (E9.1); separate guest Wi-Fi is the restaurant's job |
| 12 | Two-step sign-in, shorter back-office sessions | Done (E9.2); now also for the accountant; set-up and switch-off logged |
| 13 | Customer export, removal, retention clean-up | **Done** (E9.3 + this review: outbox, print copies, split bills, phone matches, fuller export) |
| 14 | Weighted-average cost, reasons for cost edits | Built; off by default (decision 2) |
| 15 | Daily accounting journal export | Not yet (E5.4) |
| 16 | Tests on every GitHub change, `main` protected | Done (E9.5) |
| 17 | Second-manager approval, tamper-evident history | Done (E9.4), strengthened here (H1, M1, M2) |
| 18–20 | Data-processing agreement, SOC 2 + penetration test, e-VAT | With the cloud version and E8/E10 |

## Next review

The independent penetration test before general sale (E10.5, ROADMAP S7). Run this review again then, and when Corral Cloud hosts more than one restaurant.
