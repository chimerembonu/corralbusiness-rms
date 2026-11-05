# Adding a payment provider

CorralBusiness – RMS talks to payment providers through one rulebook (`PaymentGateway`). Paystack, PaySwitch and the Demo provider all follow it. A new provider (Hubtel, Flutterwave, a bank, a MoMo aggregator…) is added **without touching the till, the reports or any other provider**.

**For the owner (non-technical):** to add a provider, open Claude Code in the project folder and paste:

> Read CLAUDE.md and docs/ADDING-A-PAYMENT-PROVIDER.md. Add **[provider name]** as a payment provider, following every step in that guide. Its developer documentation is at **[link]**. Use Http::fake for tests; never call the real service from tests. Run the full test suite and commit on the complete-edition branch.

Once it is merged, the restaurant sees the new provider on **Settings → Connections** (to fill in its keys and press **Test connection**) and on **Settings → Payment providers** (to choose it as the default). Nothing else changes.

---

## For the developer: the six steps

### 1. Write the adapter

Create `app/Modules/Payments/Gateways/Providers/<Name>Gateway.php`, extending `AbstractGateway` (which implements `PaymentGateway`). Implement:

| Method | What it must do |
|---|---|
| `key()` | Short id, e.g. `hubtel`. Must match the key in `config/payments.php`. |
| `capabilities()` | Only what the provider really supports: `MomoPrompt`, `HostedCheckout`, `StatusCheck`, `Refund`. **A provider with `MomoPrompt` must also have `StatusCheck`**, because the store server cannot receive calls from the internet. |
| `networks()` | Which `MomoProvider` networks it can prompt (MTN, Telecel, AT). |
| `test(array $values)` | A harmless call (e.g. "get balance", or "status of an id that cannot exist") that tells good keys from bad ones. Return `TestResult::ok/failed` with a plain-English message. Never charge money. |
| `requestMomo()` | Send the prompt. Return `Pending` (waiting), `NeedsOtp` (customer must read a code from their phone), `Succeeded` or `Failed`. If the call times out *after* it may have been sent, return `Pending`, not `Failed`. |
| `status()` | Ask whether it was paid. Map the provider's codes to `Succeeded`, `Failed` or `Pending`. If the provider returns the amount paid, put it in `details['amount']` (pesewas) so mismatches are caught. |
| `checkout()` | *(if `HostedCheckout`)* Start the provider's own payment page and return its URL in `checkoutUrl`. |
| `refund()`, `refundStatus()` | *(if `Refund`)* Send money back for a payment, using **our** refund reference (`$refundReference`) so the same refund sent twice is never paid twice. Return `Succeeded` (sent back), `Pending` (accepted, processing), `Failed` (refused, with the reason) or `NeedsAttention` (the provider needs a person). `refundStatus()` answers the same, or `Expired` if the provider has no refund with that reference (it is then sent again). **Throw** if the provider could not be reached: the refund is retried every minute. Without `Refund`, managers are told to give the money back by hand. |
| `submitOtp()` | *(if supported)* Otherwise leave the default, which refuses politely. |
| `newReference()` | Override only if the provider needs a special format (PaySwitch needs 12 digits). |

**Rules that are checked by tests or review:**
- Amounts are **pesewas** (integers). Convert to the provider's format inside the adapter only.
- **Never** send or store card numbers, expiry dates or CVVs. Cards go only through the provider's hosted page. (This is why PaySwitch's direct card API is not used.)
- A decline is a `Failed` response, never an exception. Throw only for "not supported".
- Use `$this->http()`, which has short time-outs, so a slow provider never freezes a till. It repeats only status questions (GET), never a payment or refund request: a slow provider may already have acted on the first one.
- Keep the provider's codes (network codes, endpoints) in `config/payments.php` under the provider's key, so they can be corrected without code changes.

### 2. Register it in `config/payments.php`

```php
'hubtel' => [
    'class' => HubtelGateway::class,
    'connection' => 'hubtel',
    'fields' => [
        'mode' => ['type' => 'select', 'options' => ['test', 'live'], 'required' => true],
        'client_id' => ['type' => 'text', 'required' => true],
        'client_secret' => ['type' => 'text', 'secret' => true, 'required' => true],
    ],
],
```

`fields` are the boxes on **Settings → Connections**. Mark keys and passwords `'secret' => true`: they are stored locked, shown only as their last 4 characters, and never logged.

### 3. Add the words

- `app/Modules/Payments/lang/en/payments.php` → `gateway.names.<key>`
- `app/Modules/Settings/lang/en/settings.php` → `connections.names.<connection>`, `connections.about.<connection>` (where the restaurant finds its keys), and `connections.fields.<field>` for any new field name.

### 4. Test it

Create `app/Modules/Payments/tests/<Name>GatewayTest.php` using `Http::fake()` with examples from the provider's documentation. Cover at least: good and bad keys, prompt sent, status succeeded, status failed, and a time-out. The **contract test** (`GatewayContractTest.php`) picks up the new provider automatically and fails if any step above was missed. Real outside calls are blocked in every test.

### 5. Try it in the provider's sandbox

Set up the provider on a test server in **test mode**, press **Test connection**, then send a real test prompt with the provider's test number. Confirm the network codes and amount format, and correct them in `config/payments.php` if needed.

### 6. Run everything and commit

`php artisan test` must pass in full, including the role guards. Commit on the `complete-edition` branch.

---

## How the pieces fit (for reference)

- **`GatewayManager`** picks the provider for each purpose (`till_momo`, `online`): the owner's default if it is ready, otherwise the first ready provider that can do it. Training-mode orders use **only** the Demo provider, and real orders **never** use it.
- **`GatewayPaymentService`** sends the prompt, keeps one prompt per order at a time, checks the status, and records the payment **exactly once** through the normal `PaymentService`, so cash-ups, reports and receipts work as for any payment. Money that arrives but cannot be attached (the order was paid in cash meanwhile, or a different amount came in) is marked **"needs a manager"** and listed on Settings → Payment providers. It is never lost.
- **`gateway_transactions`** remembers which provider handled each prompt, so changing the default never loses a payment already on its way.
- **`rms:payments:check`** runs every minute and re-checks prompts from the last 30 minutes, in case a customer approves after the cashier stopped waiting.
