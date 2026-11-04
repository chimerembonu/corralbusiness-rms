# CorralBusiness – RMS — Compliance readiness assessment

Date: 4 November 2026 · Code assessed: `main` at `3d584a5`
Prepared for: Chimere Mbonu, Corral Systems Limited
Method: current rules checked against public sources (listed at the end); code evidence gathered by an independent reviewer and the key items re-checked by hand. **This is a readiness assessment, not a certification.** SOC 2 and PCI DSS can only be confirmed by an accredited outside assessor, and data-protection, tax and accounting compliance are judged on the *business* that uses the software, not the software alone. Have the restaurant's accountant and a Ghanaian lawyer confirm the items marked for them.

## 1. Bottom line

| Framework | Does it apply? | Where the app stands | Most important next step |
|---|---|---|---|
| **Ghana Data Protection Act 2012 (Act 843)** — Ghana's equivalent of GDPR | **Yes** — to the restaurant (it holds customer and staff data) and to Corral Systems (as supplier, and fully once the cloud version exists) | **Partly ready.** Good access control and audit trail; missing privacy notice, data export/erasure, retention rules, encrypted backups and encrypted network traffic | Restaurant registers with the Data Protection Commission; add the software fixes in §8 |
| **Ghana VAT Act 2025 (Act 1151), fiscal devices and e-VAT** | **Yes** — the restaurant must issue correct tax receipts | **Rates correct; e-VAT/fiscal-device link not built.** VAT 15 %, NHIL 2.5 %, GETFund 2.5 % on the same base (20 %), COVID levy removed, each shown on the receipt | Ask GRA *now* whether the restaurant must use e-VAT or a fiscal device; plan the integration |
| **PCI DSS** (card payments) | **Yes for the restaurant** (it takes cards). **The app is outside the scope** because it never handles card numbers — provided no one types one into it | **Out of scope, with one hole to close:** the card "reference" box accepts any text, so a full card number could be typed in and would then be printed, backed up and synced | Block card numbers in that box (small fix); restaurant completes its short self-assessment (SAQ B) with its bank |
| **IFRS** (Ghana's accounting standard; US GAAP does not apply) | Applies to the restaurant's **financial statements**, not to the till software | **Good raw data, two gaps:** revenue, tax, discounts, refunds and tips are recorded separately and correctly; but stock is valued at *last purchase price* (IFRS allows only FIFO or weighted average), and there is no accounting export | Switch stock valuation to weighted average; add a daily accounting journal export |
| **GDPR** (EU) | **No** — only applies to businesses established in the EU or targeting people in the EU | Meeting Act 843 covers most of the same ground, which helps if you ever sell abroad | None now |
| **SOX** (US Sarbanes-Oxley) | **No** — only applies to companies listed on US stock exchanges | Its "internal controls" ideas are still useful and the app already has many of them | None now; strengthen the controls in §6 anyway |
| **SOC 2** | **Not a law.** A voluntary audit report that larger customers ask a *software supplier* for. Relevant to Corral Systems once you host customer data in the cloud (Phase 6) | **Early.** The app has solid building blocks, but SOC 2 also audits company policies, HR, vendor and incident processes that Corral Systems doesn't have yet | Plan a SOC 2 readiness project alongside the cloud version, not before go-live |

**In one sentence:** nothing here blocks the 13 November go-live, but the restaurant needs to do two business tasks (register with the Data Protection Commission, check its e-VAT/fiscal-device status with GRA), and five small software fixes should go in first (§8, "Before go-live").

## 2. Ghana Data Protection Act 2012 (Act 843)

**Why it matters now.** The Data Protection Commission (DPC) declared 2026 a year of enforcement and in October 2026 announced spot checks, inspections and complaint investigations after registering 2,575 data controllers this year. Act 843 remains the governing law.

**Who is responsible for what**
- The **restaurant** is the *data controller* for its customers' and staff's data. It must register with the DPC and follow the Act's principles (collect only what it needs, tell people, keep it secure, keep it no longer than needed, let people see and correct their data).
- **Corral Systems** is a *data processor* while the system runs on the restaurant's own server, and becomes a full processor holding data once the cloud version exists. Corral Systems should register for its own operations and sign a data-processing agreement with each customer.

**What personal data the app holds:** customer names, phone numbers, emails, addresses and notes; names and phones on orders and deliveries; staff names, emails, phones, job titles, photos, PIN and password (both securely scrambled), clock-in records; supplier contacts; device addresses in sign-in sessions. **No** dates of birth, ID numbers, bank details or card numbers.

**Already in place**
- Every screen behind a login; staff see only what their role allows (tested for all 8 roles, see ROLE-ACCESS-REVIEW.md).
- PINs and passwords stored scrambled; PIN guessing locked out after 5 tries.
- Leavers switched off immediately with the "active" switch.
- Full change history (audit log) on customers, staff, orders and payments.
- No customer data leaves the server: no email, SMS, tracking or third-party scripts.
- Nightly backups.

**Gaps**
1. **No privacy notice.** Customers aren't told what is collected or why. Fix: a short notice at the counter and on the receipt footer (the footer is already editable in settings); a staff notice in the employment pack.
2. **No way to hand someone their data, or to erase it.** Deleting a customer only hides the record, and their name and phone stay on old orders. Fix: an "Export this customer's data" button and an "Anonymise" action that removes the name, phone and address everywhere while keeping the sales figures.
3. **No retention rules.** Data is kept forever. Fix: agree retention periods with the accountant (tax records must be kept for a set number of years) and add a scheduled clean-up for anything older.
4. **Backups are not encrypted** and can be copied to a USB stick. A lost stick exposes every customer and staff member. Fix: password-protect backup files.
5. **Network traffic is not encrypted.** The store server serves plain web pages, so PINs, passwords and customer details cross the restaurant Wi-Fi unprotected. Fix: switch the server to encrypted connections (HTTPS) on the local network, and keep tills on a separate Wi-Fi from guests.
6. **Failed sign-ins are not recorded**, so a guessing attempt leaves no trace. Fix: log them.
7. **No breach procedure.** Fix (business): a one-page plan of who to call and how to tell the DPC and affected people if data is lost.

## 3. Ghana tax: VAT Act 2025, fiscal devices and e-VAT

**Rates.** From 1 January 2026, VAT is 15 % and NHIL and GETFund are 2.5 % each, all charged on the same base (no more tax-on-tax), for a total of 20 %. The COVID-19 levy is abolished and the flat-rate scheme is gone. The app's defaults match exactly (`GhanaTaxDefaults.php`), calculate each levy separately, store them on every order and line, and print each one on the receipt with the restaurant's TIN. ✓

**Receipts and GRA systems — the open question.**
- The new VAT Act lets a receipt from an approved **fiscal electronic device** count as the tax invoice, and says use of these devices becomes mandatory in key sectors during 2026. GRA has said it will fully enforce the Fiscal Electronic Device Act this year.
- Separately, GRA's **e-VAT** system (invoices sent to GRA electronically) is expanding. A report says Tier-2 medium enterprises are due to be added from 1 November 2026, but GRA had not officially confirmed this at the time of writing.
- **CorralBusiness – RMS does not connect to e-VAT or to a fiscal device.** Its receipts are correct and complete, but they are not GRA-certified.

**What to do:**
1. The restaurant asks its GRA tax office this week whether it has been enrolled for e-VAT or must use a fiscal device, and from when.
2. If yes: either use GRA's approved device or app alongside the till for now, or Corral Systems builds the e-VAT connection. This was planned for Phase 8 and should move earlier if GRA says so. For you, it is also a selling point: few local POS systems are integrated.
3. Small additions: an optional **customer TIN** on a receipt (business customers need it to claim input VAT), and confirm with the accountant that numbering orders from 1 each day, combined with the date and branch, is acceptable as an invoice number.

## 4. PCI DSS (card payments)

**How the app handles cards today:** the card is paid on the bank's own **standalone terminal**. The till only records "paid by card" and an optional slip number. The app never reads, sends or stores card numbers. That keeps CorralBusiness – RMS **outside** the PCI DSS "card data environment". The restaurant's own obligation is the short self-assessment for standalone terminals (SAQ B), done with its bank (acquirer).

**The one hole:** the card reference box accepts any text up to 100 characters. The screen hint says "slip number or last 4 digits", but nothing stops a cashier typing the full 16-digit number. That number would then be printed on the receipt, saved in every backup and queued for cloud sync, which would pull the whole server into PCI scope. **Fix (small):** reject anything that looks like a card number (13–19 digits, or passes the standard card-number check) in the reference and note fields, and accept only digits for a "last 4".

**Later (Phase 7, payment gateways):** keep card entry entirely on the provider's side, through a hosted payment page or a provider-certified terminal, so the app stays out of scope. Never build card entry into the app itself.

**Version note:** PCI DSS v4.0.1 is the standard in force; its "future-dated" requirements became mandatory on 31 March 2025.

## 5. Accounting standards: IFRS (and why US GAAP doesn't apply)

Ghana's accounting body (ICAG) has adopted **IFRS**; smaller entities may use **IFRS for SMEs**. US GAAP doesn't apply to a Ghanaian restaurant. These standards govern the restaurant's *financial statements*, which its accountant prepares. The till's job is to give the accountant complete, accurate and traceable records.

**Already right**
- Sales recorded **net of tax**, with VAT and each levy stored separately, which is needed for revenue and the VAT return.
- **Discounts** reduce the sale and are recorded with reason and approver; **refunds** and **voids** are recorded as reversals, never deleted (a line removed *before* the bill is printed is simply dropped, which is fine).
- **Tips** are kept separate from sales (they belong to staff, not revenue). **Service charge** is recorded separately, so the accountant can treat it either way.
- **Customer accounts** (credit sales) have their own ledger: amounts owed, payments received.
- **Daily close (Z report)** is frozen when the day closes, and reopening keeps the original.
- No screen exists to edit or delete a paid order or payment.

**Gaps**
1. **Stock valuation method.** Each purchase overwrites an ingredient's cost with the latest price, and managers can also type a cost by hand. IFRS (IAS 2) allows only **FIFO or weighted average** cost, so stock value and food cost figures from the app won't match what the accountant must report. **Fix:** weighted-average cost on each delivery; hand edits only with a reason, logged.
2. **No accounting export.** The accountant would re-type daily totals. **Fix:** a daily journal export (sales by category, VAT, NHIL, GETFund, discounts, refunds, tips, service charge, payment methods, cash over/short) to Excel, mapped to the restaurant's chart of accounts. This was in Phase 8; it is cheap and worth bringing forward.
3. **Gift vouchers and loyalty points** (later phases) must be recorded as money owed to customers until used, not as sales when issued. Build them that way when the time comes.

## 6. Internal controls (the useful part of SOX)

SOX is a US law for companies listed on US stock exchanges, so it **does not apply** to the restaurant or to Corral Systems. The controls it asks for are still good practice, and some customers (international chains, franchises) will ask about them.

**Already in place:** role-based access with tested guards; manager PIN for voids, refunds, large discounts, day close and reopen; cashiers count their drawer without seeing what it should contain; frozen Z reports; a complete change history on money records; no deletion of paid orders; automated tests (433) guarding money and access rules.

**Gaps**
1. **A manager can approve their own actions.** A manager who voids, refunds or closes the day is also the approver. Fix (setting): optionally require a *second* person's PIN for refunds and day close above an amount.
2. **The change history can be altered by someone with database access.** It is a normal table. Fix: add a running checksum so tampering shows, and copy it off the server nightly (cloud backups in Phase 6).
3. **Change control.** The automated check on GitHub builds the server package but **does not run the 433 tests**, and `main` is not protected. Fix: run the tests on every change and require them to pass before merging.
4. **Updates don't stop on a failed backup.** `scripts/update.sh` continues even if the pre-update backup fails. Fix: stop the update if the backup fails.
5. **No alerting.** Errors are written to a log file nobody watches. Fix: email or SMS the owner when backups fail or errors spike.

## 7. SOC 2 (later, for Corral Systems)

SOC 2 is an audit report, produced by an independent CPA firm, on how a **service company** protects customer data (security, plus optionally availability, confidentiality, processing integrity and privacy). Restaurants won't ask for it; hotel groups, chains and payment partners will once you host their data in the cloud. A "Type I" report checks your controls on one day; a "Type II" checks that they worked over 3–12 months.

**Software foundations already there:** role-based access, audit trail, backups, documented security reviews, automated tests.
**Software gaps to close for SOC 2:** encrypted connections (HTTPS) everywhere, multi-factor sign-in for the back office, shorter back-office sessions (today 12 hours), logging of failed sign-ins, encrypted backups, monitoring and alerting, tests required before every release.
**Company gaps (not software):** written security policies, risk assessment, staff background checks and training, supplier reviews (hosting provider), incident response plan, access reviews every quarter, and evidence that you follow all of these.

**Recommendation:** start a SOC 2 readiness effort when Phase 6 (cloud) begins. Many firms use a compliance platform to collect evidence, then aim for Type I about 6 months later and Type II about 6–12 months after that.

## 8. Action plan

**Before go-live (software — about one Claude Code session)**
1. Block card numbers in the card reference and note fields (PCI).
2. Password-protect backup files (Act 843).
3. Stop `update.sh` if the pre-update backup fails (controls).
4. Log failed back-office sign-ins and PIN attempts (Act 843 / controls).
5. Add an optional customer TIN to receipts (VAT).

**Before go-live (business — the restaurant and you)**
6. Restaurant registers with the Data Protection Commission (dataprotection.org.gh).
7. Restaurant asks GRA about its e-VAT / fiscal-device obligations and dates.
8. Restaurant completes PCI SAQ B with its bank for the card terminal.
9. Put a short privacy notice on the counter and in the receipt footer; give staff a data notice.
10. Accountant confirms: invoice numbering, retention periods, and service-charge treatment.

**First month after go-live (software)**
11. Encrypted connections (HTTPS) on the store server; tills on a separate Wi-Fi from guests.
12. Multi-factor sign-in for Owner and Manager in the back office; back-office session limited to a few hours.
13. Customer data export and anonymise actions; scheduled retention clean-up as agreed with the accountant.
14. Weighted-average stock costing; reasons required for manual cost edits.
15. Daily accounting journal export.
16. Run the tests on every GitHub change; protect `main`.
17. Optional second-person approval for refunds and day close above a set amount; tamper-evident audit log.

**With the cloud version (Phase 6)**
18. Data-processing agreement template for customers; Corral Systems DPC registration as processor.
19. SOC 2 readiness project; independent penetration test (as already recommended).
20. e-VAT integration if not already required earlier.

## Sources

- Ghana Data Protection Commission — [registration](https://dataprotection.org.gh/registration/), [guidelines to demonstrate compliance](https://dataprotection.org.gh/wp-content/uploads/2025/07/GUIDELINES-TO-DEMONSTRATE-DATA-PROTECTION-COMPLIANCE.pdf)
- GNA, Oct 2026 — [DPC strengthens enforcement after registering 2,575 data controllers](https://gna.org.gh/2026/10/dpc-strengthens-enforcement-after-registering-2575-data-controllers/)
- GNA, Jan 2026 — [2026 will be a year of enforcement](https://gna.org.gh/2026/01/2026-will-be-a-year-of-enforcement-data-protection-commission-warns/)
- EY Tax News, Jan 2026 — [Ghana enacts indirect tax laws effective 1 January 2026](https://taxnews.ey.com/news/2026-0163-ghanas-parliament-enacts-several-indirect-tax-related-laws-effective-1-january-2026)
- Crowe Ghana — [Ghana VAT reform 2026](https://www.crowe.com/gh/news/ghana-vat-reform-2026)
- Graphic Online — [VAT rate cut, flat rate scheme scrapped, COVID levy abolished](https://www.graphic.com.gh/business/business-news/gra-announces-overhaul-vat-rate-cut-flat-rate-scheme-scrapped-covid-levy-abolished-from-jan-1.html)
- GRA — [e-VAT](https://gra.gov.gh/e-vat/), [E-VAT guidelines](https://gra.gov.gh/wp-content/uploads/2024/07/E-VAT-GUIDELINES_20240222.pdf)
- Fiscal Requirements, Oct 2026 — [further e-VAT expansion reported for November 2026](https://www.fiscal-requirements.com/news/6142-ghana-clarifies-digital-vat-collection-rules-as-further-e-vat-expansion-is-reported-for-november-2026)
- VATupdate, Sep 2025 — [Ghana to enforce Fiscal Electronic Device Act by early 2026](https://www.vatupdate.com/2025/09/02/ghana-to-enforce-fiscal-electronic-device-act-for-vat-compliance-by-early-2026)
- SecurityMetrics — [Performing an SAQ B v4.0 self-assessment](https://www.securitymetrics.com/blog/performing-an-saq-b-version-40-self-assessment)
- PCI SSC — [Which version of PCI DSS should I use?](https://www.pcisecuritystandards.org/faq/articles/Frequently_Asked_Question/What-version-of-PCI-DSS-should-I-use)
- ICAG — [Adoption of IFRS for SMEs](https://www.icagh.org/wp-content/uploads/2021/03/Adoption-of-IFRS-for-SMEs-publication.pdf)
