# CorralBusiness – RMS — Step-by-step roadmap to the full application

Written 4 November 2026. Tick each box as it is done. Goes with PLAN.md (the "why") and CLAUDE.md (the rules).

**Who does what**
- **Claude** builds, tests and saves the software. Either ask in this chat ("do step E1.2") or paste the step's prompt into Claude Code on your Mac. **Do one step at a time, in one place at a time**, so two sessions never change the same code at once.
- **You** do the steps marked 👤: buying things, accounts with other companies, decisions, and checks at the restaurant.

**The prompt for any build step** (replace the step number):
> Read CLAUDE.md, PLAN.md and ROADMAP.md. Work on the complete-edition branch. Do step **E1.2** exactly as ROADMAP.md describes. Write tests that prove it works, run the full test suite, commit and push. End with what works now, what's next, and anything you need from me.

---

## Start these now: they take weeks and are not software

| # | 👤 Task | Why | Needed by |
|---|---|---|---|
| S1 | Ask PaySwitch for a **test merchant account** (merchant ID, API user, API key) | Check PaySwitch against its test system | E2.3 (mid-Nov) |
| S2 | Ask the restaurant to ask **GRA** about e-VAT / fiscal device obligations and dates; ask GRA for **e-VAT API documentation and test access** for Corral Systems | Without GRA's approval e-VAT cannot go live | E8.1 (Jan) |
| S3 | Start **WhatsApp Business** verification for Corral Systems (Meta Business account) | Verification can take weeks | E4.6 (Dec) |
| S4 | Register an **SMS sender ID** (e.g. "CorralRMS") on your MTech A2P route | Sender IDs need operator approval | E4.5 (Dec) |
| S5 | Choose a **server provider** for Corral Cloud (e.g. DigitalOcean, Hetzner, AWS Cape Town) and buy a **domain** (e.g. corralbusiness.app) | Corral Cloud runs there | E3.2 (late Nov) |
| S6 | Register Corral Systems with the **Data Protection Commission** as a data processor | Required once you hold restaurants' data in the cloud | E3 (late Nov) |
| S7 | Get a quote from a **penetration-testing firm** for January | Independent check before general sale | E10.5 (Jan) |

---

## Part A — The pilot restaurant (main branch)

| # | Step | Who | When | Done when |
|---|---|---|---|---|
| A1 | Install **Docker Desktop** on your Mac; ask Claude Code to "build the store-server Docker image and confirm it starts" | 👤 + Claude | This week | Image builds and the app opens from it |
| A2 | Buy the **mini PC** (Ubuntu 24.04, 8 GB RAM, SSD, Ethernet). Run `install.sh` following docs/STORE-SERVER.md. **Write down the backup password** it shows | 👤 | By 8 Nov | Server shows the start page on the restaurant network |
| A3 | Restaurant: **Ethernet cables** to tills and printers; a fixed address for the server on the router; tills on a separate Wi-Fi from guests | 👤 (restaurant) | By 9 Nov | Every till and printer is on the restaurant network |
| A4 | **Real data**: menu with prices, staff list (name, role, phone), opening float, receipt header with TIN. Import with the Excel templates | 👤 + Claude | By 10 Nov | Real menu and staff are in the system |
| A5 | **Accountant confirms** tax rates, invoice numbering, record-keeping period, and how service charge is treated | 👤 | By 10 Nov | Written confirmation |
| A6 | **Business compliance**: restaurant registers with the DPC; privacy notice at the counter and in the receipt footer; PCI SAQ B with its bank; GRA question (S2) | 👤 (restaurant) | By 12 Nov | Done or in progress |
| A7 | **Load test** on the mini PC: `rms:load-test` with 6 tills | Claude (you run one command) | 11 Nov | Screens stay fast |
| A8 | **Practice day** in training mode with real staff; break things on purpose (see the checklist in my earlier message) | 👤 + staff | 11–12 Nov | Problems listed and fixed |
| A9 | Optional: **private remote link** (Tailscale) so the owner can see reports from anywhere. Prompt: "Add optional Tailscale remote access to install.sh and docs/STORE-SERVER.md, owner's phone only, never open the router" | Claude + 👤 | By 12 Nov | Owner opens reports on mobile data |
| A10 | **Go-live checklist** (PROMPTS.md "Go-live"), then **go live on 13 November** | 👤 + Claude | 13 Nov | First real day traded |
| A11 | **Two days of shadowing**; fixes go out through `scripts/update.sh` (backs up first) | 👤 + Claude | 13–14 Nov | No open urgent problems |
| A12 | After **two calm weeks** (about 27 Nov): lift the freeze and start bringing Complete Edition stages into the pilot, each switched off until you turn it on | Claude | ~27 Nov | Pilot running on the merged code |

---

## Part B — The Complete Edition (complete-edition branch)

Every feature is **off by default**. A restaurant switches it on in **Settings → Features** once its connection works.

### E1 — Plug-and-play foundation
- [x] **E1.1** Features page and Connections page (keys stored locked, Test connection). *Done 4 Nov.*
- [x] **E1.2 Licence and plans.** Corral Systems issues a licence code per restaurant (Starter / Pro / Business, number of branches, end date), checked by the app **without internet**. A Licence page shows the plan and expiry. Features outside the plan stay off. An expired licence gives a 14-day grace period, then read-only reports, and **never stops a till mid-service**. 👤 *You decide which features go in which plan (see the pricing notes from 1 Nov) and keep Corral's signing key safe.* *Done 4 Nov: Settings → Licence; codes made with `rms:licence-issue`; plan of each feature in config/features.php ("tier"), to be confirmed by the owner.*
- [x] **E1.3 First-run setup wizard.** On a fresh install the browser opens a wizard: restaurant name, TIN, branch, owner account, taxes (2026 Ghana defaults, to confirm), receipt, tills, menu and staff from Excel, and the backup password shown and confirmed. No command line needed. *Done 5 Nov: `/setup`, opened by every screen of a fresh install; the installer prints a 6-digit setup code; `rms:setup` still works.*
- [x] **E1.4 Printers in the wizard.** The wizard scans the network and USB for printers, prints a test page on each, and assigns them to kitchen stations and tills. *Done 5 Nov: the tills screen also names the kitchen stations; the installer records the restaurant network and prepares USB printers (`scripts/usb-printers.sh`). Still to try on the real printers at the restaurant.*

### E2 — Payments
- [x] **E2.1** Plug-in payment providers (Paystack, PaySwitch, Demo), a default per purpose with automatic fallback, Mobile Money prompt at the till, background re-checks, a "needs a manager" list, and the guide for adding providers. *Done 4 Nov.*
- [x] **E2.2 Paystack test check.** 👤 Put the Paystack **test** keys into Settings → Connections on your Mac and press Test. Send a test prompt with Paystack's test number. Claude corrects network codes or amounts if Paystack disagrees. *Done 5 Nov: keys tested; a GH₵ 12.00 MTN prompt to Paystack's test number 055 123 4987 was approved and Paystack confirmed 1200 pesewas GHS; codes match Paystack's docs. Telecel and AT have no Paystack test numbers: confirm with one small real payment each before going live.*
- [ ] **E2.3 PaySwitch test check.** 👤 Same with the PaySwitch test account (S1).
- [x] **E2.4 Refunds through the provider.** A manager refunds a MoMo prompt payment from the till and the money goes back through the same provider (where supported); otherwise the till says "refund by hand" and records it. *Done 5 Nov: sent in the background with our own refund reference (never twice), followed every minute until done; PaySwitch, refusals and Paystack "needs attention" become "refund by hand", which a manager confirms with a PIN; the owner sees them on Payment providers. Tried on the Mac: Paystack test refund 19014268 accepted for GH₵ 12.00.*
- [x] **E2.5 Reconciliation report.** The day's provider payments beside the till's records: matched, missing, "needs a manager"; Excel export. *Done 5 Nov: Reports → Payment matching (owner, manager, accountant), any date range, Excel and PDF. "Compare with Paystack" fetches Paystack's own list in the background and catches money the till missed, money Paystack never got, and payments no till knows about. PaySwitch has no list API: compare on its dashboard. Tried on the Mac against Paystack's test system.*

### E3 — Online service (Corral Cloud and self-hosted)
- [x] **E3.1 Branch separation (D2).** Every back-office list and kitchen screen is limited to its own branch (needed before more than one branch). *Done 5 Nov: 13 back-office lists, their drop-downs and sub-lists, the payment providers lists and the kitchen live channel; a guard test fails if a new branch list forgets. The menu, customers, suppliers and promo codes stay shared by the whole restaurant. Choosing across branches (e.g. prices per branch) moves to head office in E3.5.*
- [ ] **E3.2 Cloud package.** The same app in "cloud mode" with HTTPS, deployed to the server from S5. 👤 *Server account, domain, DNS.*
- [ ] **E3.3 Corral Cloud admin.** Your own admin area: create restaurants, issue activation codes and licences (E1.2), see who is online, support access only with the restaurant's permission.
- [ ] **E3.4 Sync.** The store server sends sales, payments, stock and staff hours to the cloud whenever internet is available, and receives menu and price changes made in head office. It catches up after outages, and a status screen shows the last sync.
- [ ] **E3.5 Owner's dashboard from anywhere.** Phone-friendly head-office reports across all branches.
- [ ] **E3.6 Off-site backups.** The nightly locked backup is copied to the cloud and can be restored onto a replacement server.
- [ ] **E3.7 Self-hosted options.** Install the cloud part on the restaurant's own server instead of Corral Cloud, plus the Tailscale private link (A9) packaged for every customer.

### E4 — Online ordering and messages
- [ ] **E4.1 Online menu and cart.** The restaurant's ordering page: menu with photos, sold-out items hidden, opening hours, pickup or delivery, delivery zones and fees.
- [ ] **E4.2 Online payment.** Customers pay on Paystack's or PaySwitch's secure page; the cloud confirms the payment.
- [ ] **E4.3 Orders reach the restaurant.** Online orders appear on the tills and kitchen screens through sync; staff accept, give a ready time, or reject (automatic refund).
- [ ] **E4.4 Order tracking page** for the customer: received, cooking, ready, out for delivery.
- [ ] **E4.5 SMS.** Confirmations and "your order is ready", through MTech A2P or any SMS gateway. 👤 *Sender ID (S4).*
- [ ] **E4.6 WhatsApp.** The same messages by WhatsApp. 👤 *Verification (S3).*

### E5 — Kitchen and stock depth
- [ ] **E5.1 Recipes.** Each dish and size lists its ingredients; selling it uses up stock automatically.
- [ ] **E5.2 Correct stock value.** Weighted-average cost (allowed by IFRS), purchase price history, and a reason required for manual cost changes.
- [ ] **E5.3 Food cost and margin** per dish and category; theoretical vs actual usage after a stock take.
- [ ] **E5.4 Accountant's daily export.** A daily journal (sales, VAT, NHIL, GETFund, discounts, refunds, tips, service charge, payment methods, cash over/short) to Excel. 👤 *Accountant gives account codes.*

### E6 — Payroll (optional)
- [ ] **E6.1** Pay rates, overtime, allowances and deductions; SSNIT and PAYE as editable tables; pay runs; payslip PDFs; Excel export. 👤 *Accountant confirms 2026 SSNIT and PAYE figures.*

### E7 — Guests
- [ ] **E7.1 Reservations.** Bookings, table holds, deposits via E2, reminders via E4.
- [ ] **E7.2 Loyalty points**, with a connector for Corral Rewards.
- [ ] **E7.3 Gift vouchers**, recorded as money owed until used.

### E8 — Tax, channels and extras
- [ ] **E8.1 GRA e-VAT.** Each receipt sent to GRA, built to GRA's specification and tested on GRA's test system. 👤 *GRA access (S2).*
- [ ] **E8.2 Delivery-app orders.** Bolt Food and Glovo orders entered at the till with the app's commission.
- [ ] **E8.3 Customer-facing display** showing the order and total.
- [ ] **E8.4 Barcode scanner** for packaged items.
- [ ] **E8.5 Languages.** Twi, Ga and French for staff screens. 👤 *Translators check the wording.*

### E9 — Security and compliance finish
- [ ] **E9.1** Encrypted connections (HTTPS) on the restaurant network.
- [ ] **E9.2** Two-step sign-in for Owner and Manager; shorter back-office sessions; tills lock themselves when left idle.
- [ ] **E9.3** Customer data export and anonymise; automatic clean-up after the agreed retention period.
- [ ] **E9.4** Tamper-evident audit log; optional second-person approval for big refunds and day close.
- [ ] **E9.5** Tests run automatically on every GitHub change; `main` protected; the remaining items from the security reviews (D3, N3, N4, N6, N7).
- [ ] **E9.6** Re-run the security, role and compliance reviews on the finished edition.

### E10 — Packaging and launch
- [ ] **E10.1 The box.** A mini-PC image that installs itself and opens the setup wizard on first start.
- [ ] **E10.2 The download.** Installers for Ubuntu, Windows and Mac.
- [ ] **E10.3 Illustrated install guide** and short training videos. 👤 *You record or approve.*
- [ ] **E10.4 Sales side**: plans and prices, licence sales through Corral Cloud, support process. 👤
- [ ] **E10.5 Independent penetration test** (S7) and fixes.
- [ ] **E10.6 Launch.** The Complete Edition goes into the pilot restaurant first, then on general sale.

---

## Timeline (target)

| Weeks of | Pilot (Part A) | Complete Edition (Part B) |
|---|---|---|
| 4–13 Nov | A1–A11, go live 13 Nov | E1.2–E1.4 in quiet hours; E2.2 when keys arrive |
| 16 Nov | Shadowing, fixes | E2.3–E2.5 |
| 23 Nov – 7 Dec | A12: merge (~27 Nov) | E3.1–E3.7 |
| 7–21 Dec | Features switched on one by one | E4.1–E4.6 |
| 21 Dec – 4 Jan | | E5, E6 |
| 4–18 Jan | | E7, E8 |
| 18–31 Jan | | E9, E10 → **ready to sell end of January 2027** |

The pilot always comes first: if the restaurant needs a fix, Part B pauses.
