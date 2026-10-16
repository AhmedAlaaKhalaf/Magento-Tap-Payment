# Tap_Payment: Tap Payments for Magento 2

A Magento 2 payment method that sends customers to Tap's hosted checkout. When the customer clicks **Place Order**, the module calls Tap's `/v2/charges` or `/v2/authorize` API (chosen by **Transaction Mode**) and redirects the customer to the returned `transaction.url`. The result arrives two ways: the customer's return to the store, and Tap's server-to-server webhook. Either one updates the order:

- **Paid:** the order moves to **Processing**, and the Tap id (`chg_…` or `auth_…`) is recorded under **Transactions**. For charges an invoice is created. The customer sees the success page.
- **Failed, declined or canceled:** the order is **Canceled** and the cart is restored. The customer is sent back to the cart with a message explaining what happened.

Built on Magento's Payment Gateway framework: a facade, a command pool, request builders, a transfer factory, a client, a validator and a handler. There's no legacy `AbstractMethod`.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.6 or later (verified against 2.4.8)
- PHP 8.2, 8.3 or 8.4

## Installation

Copy the module into `app/code/Tap/Payment`, or require it through a Composer path repository (package `tap/module-payment`). Then run:

```bash
bin/magento module:enable Tap_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode
bin/magento cache:flush
```

## Configuration

Go to **Stores > Configuration > Sales > Payment Methods > Tap Payments**.

| Field | Type | Notes |
| --- | --- | --- |
| Enable Gateway | Yes/No | Shows the method in checkout |
| Title | Text | Label shown to customers |
| Test Public Key | Obscured, **encrypted** | `pk_test_…`. Stored with `Magento\Config\Model\Config\Backend\Encrypted`. The hosted flow doesn't need it, but it's kept for Tap's card SDK. |
| Test Secret Key | Obscured, **encrypted** | `sk_test_…`. Used for API calls and to verify webhook signatures. |
| Transaction Mode | Charge / Authorize | Charge calls `POST /v2/charges` (captured immediately). Authorize calls `POST /v2/authorize` (funds held). |
| Applicable Countries | All / specific | Standard Magento country restriction |
| Debug Logging | Yes/No | Logs requests and responses to `var/log/tap_payment.log`, with customer names, email and phone masked |

Tap's public sandbox keys are on the [Testing Keys](https://developers.tap.company/reference/testing-keys) page.

**Webhook:** every request sends `post.url = {store}/tap/payment/webhook`, so nothing needs configuring on Tap's side. Tap cannot post to `localhost`, so for local testing expose the store with a tunnel (for example ngrok). The customer return flow works without the webhook.

## Payment flow

```mermaid
sequenceDiagram
  autonumber
  participant C as Customer
  participant M as Magento
  participant T as Tap API
  participant H as Tap hosted page

  C->>M: Place Order
  M->>T: POST /v2/charges or /v2/authorize (amount, customer, reference.order, redirect.url, post.url)
  T-->>M: id chg_ or auth_, status INITIATED, transaction.url
  Note over M: order saved as pending_payment with the Tap id and URL; no email yet
  M-->>C: checkout JS goes to /tap/payment/redirect
  M-->>C: 302 to transaction.url
  C->>H: pays with card / KNET / mada / ...
  par customer returns
    H-->>C: 302 to /tap/payment/response?tap_id=chg_...
    C->>M: GET /tap/payment/response
  and webhook
    T->>M: POST /tap/payment/webhook (charge JSON + hashstring header)
  end
  M->>T: GET /v2/charges/{id} (status is always re-read from Tap)
  alt CAPTURED (charge) or AUTHORIZED (authorize)
    Note over M: capture or authorization transaction = Tap id, invoice for captures, state Processing, confirmation email
    M-->>C: success page
  else DECLINED, CANCELLED, FAILED, ABANDONED, ...
    Note over M: order canceled (stock released), cart restored
    M-->>C: cart page with an error explaining the status
  else INITIATED, IN_PROGRESS, UNKNOWN
    Note over M: still pending_payment; the webhook finishes it later
  end
```

### Order lifecycle

| Tap status | Charge mode | Authorize mode | Magento order |
| --- | --- | --- | --- |
| `INITIATED` (after Place Order) | | | `pending_payment` |
| `CAPTURED` | success | success | **Processing**, capture transaction, invoice |
| `AUTHORIZED` | (not final) | success | **Processing**, open authorization transaction |
| `DECLINED`, `CANCELLED`, `FAILED`, `ABANDONED`, `RESTRICTED`, `VOID`, `TIMEDOUT` | failure | failure | **Canceled**, cart restored |
| `INITIATED`, `IN_PROGRESS`, `UNKNOWN` (on return) | pending | pending | stays `pending_payment` |

If the customer never comes back and no webhook arrives, Magento's own cron cancels the order after **Stores > Configuration > Sales > Sales > Orders Cron Settings > Pending Payment Order Lifetime**.

## Design decisions

- **The Tap call happens inside Place Order.** The method uses the gateway `initialize` command (`can_initialize = 1`). If Tap rejects the request, for example because of a bad key or amount, Place Order fails with an error and no orphan order is created. On success, the order is created in `pending_payment` and the order email is held back until payment.
- **Nothing is trusted from the browser or the webhook body.** Both the return URL and the webhook re-read the charge with `GET /v2/charges/{id}` or `GET /v2/authorize/{id}` and act only on that.
- **A charge can only update its own order.** The charge's `reference.order` finds the order, and the order must have stored that same Tap id at Place Order (`OrderLocator`).
- **Webhook authenticity.** The `hashstring` header is verified with HMAC-SHA256 as [documented by Tap](https://developers.tap.company/docs/webhook), with the amount formatted to the currency's decimals (3 for KWD, BHD, OMR and JOD). The route skips Magento's form-key CSRF check (`CsrfAwareActionInterface`), because the signature is its authentication.
- **Exactly once.** The return and the webhook often arrive together. `PaymentResultProcessor` takes a per-order lock (`LockManagerInterface`), reloads the order, and only changes it while it is still `pending_payment`. A late or duplicate notification is a no-op.
- **Idempotent charge creation.** `reference.idempotent` combines store, order number, mode, amount and currency. If Place Order is retried, Tap returns the same charge instead of creating a second one. If the cart total changes, a new charge is created.
- **Amount check.** If Tap reports a different amount or currency than the order, the payment is recorded but flagged as fraud (Magento's payment review), with an order comment.
- **Safe cart restore.** The cart is restored only when this browser session placed the order (checkout session `last_real_order_id`). A forwarded return link can't load someone else's cart.
- **Safe redirect.** `/tap/payment/redirect` only follows `https://*.tap.company` URLs.

## Testing

### On a store (sandbox)

1. Enter the test keys, set Transaction Mode, and enable the method.
2. Check out, choose **Credit / Debit Card (Tap Payments)**, and place the order.
3. On Tap's page, pay with a [test card](https://developers.tap.company/reference/testing-cards):

| Scenario | Card | Expiry | CVV |
| --- | --- | --- | --- |
| Approved (3-D Secure) | `4508 7500 1574 1019` | `01/39` | `100` |
| Approved (no 3DS) | `5111 1111 1111 1118` | `01/39` | `100` |
| Declined | `4508 7500 1574 1019` | `05/22` | `100` |
| Timed out | `4508 7500 1574 1019` | `08/28` | `100` |

4. Check the order: **Processing**, with the Tap id under **Transactions** and the invoice (charge mode). Or **Canceled**, with the cart restored and an error on the cart page.

### Automated checks (no store needed)

`.dev/` (gitignored) holds a portable PHP 8.3 build and the Magento 2.4.8 codebase from the [Mage-OS mirror](https://mirror.mage-os.org/). It isn't installed: there's no database.

```bash
# 46 unit tests, run against real Magento classes
.dev/bin/php .dev/magento/vendor/bin/phpunit

# Calls Tap's sandbox through the module's own builders, client, validator, handler and TapApi
.dev/bin/php dev/tap-sandbox-smoke.php charge
.dev/bin/php dev/tap-sandbox-smoke.php authorize
.dev/bin/php dev/tap-sandbox-smoke.php status chg_...   # after paying on the printed hosted page
```

Inside an existing Magento project, set `MAGENTO_ROOT=/path/to/magento` instead.

Verified so far:

- **PHPUnit:** 46 tests and 144 assertions. They cover the request builders, phone normalization, transfer and auth headers, the response validator and handler, the paid, failed, pending, already-final, amount-mismatch and lock paths, the webhook signature, the controllers' redirect safety and outcomes, and order ownership checks.
- **`bin/magento setup:di:compile` on Magento 2.4.8:** succeeds with the module enabled. Every `di.xml` virtual type and constructor resolves.
- **Magento coding standard** (`phpcs --standard=Magento2`): 0 errors. The warnings are docblock style, line length, and the static value helpers `Amount` and `ChargeStatus`.
- **Tap sandbox:** charge and authorize requests built by the module return `INITIATED` with a hosted `transaction.url`, and are read back by id.

Not verified here: a full storefront checkout. That needs MySQL and OpenSearch, which weren't installed on this machine.

## Code map

```
etc/config.xml, etc/adminhtml/system.xml   defaults and admin fields (encrypted keys, transaction mode)
etc/di.xml                                 gateway facade, command pool, builders, logger
etc/frontend/routes.xml                    /tap/* routes
Gateway/Config/Config.php                  typed config, key decryption, endpoint selection
Gateway/Request/*Builder.php               charge/authorize payload: amount, customer, URLs
Gateway/Http/TransferFactory.php           URI, Bearer auth, JSON body
Gateway/Http/Client/TapClient.php          HTTP client with debug logging
Gateway/Validator/InitializeResponseValidator.php   requires an id and transaction.url
Gateway/Response/InitializeHandler.php     stores the Tap id and URL; order stays pending_payment
Controller/Payment/Redirect.php            sends the customer to transaction.url
Controller/Payment/Response.php            customer return: success page, or cart with an error
Controller/Payment/Webhook.php             server-to-server notification
Model/PaymentResultProcessor.php           applies the verified result once (transaction, invoice, cancel)
Model/OrderLocator.php, Model/Api/TapApi.php, Model/Webhook/SignatureValidator.php, Model/QuoteRestorer.php
view/frontend/web/js/...                   checkout renderer (redirect after Place Order)
Test/Unit/                                 PHPUnit suite
dev/tap-sandbox-smoke.php                  sandbox check without a store
```

## Out of scope and limitations

- Only **test** keys are configurable, as the brief specifies. Live keys would be one more pair of encrypted fields plus a mode switch.
- **Online capture, refund and void** from the Magento admin aren't implemented. An authorized order can be invoiced offline in Magento after capturing in the Tap dashboard.
- The order is placed before payment, which is Magento's standard redirect-gateway pattern. Abandoned attempts become canceled orders, either through the cancel flow or the pending-payment cron.

## Magento compared with Shopify (for the walk-through)

| | Magento (this module) | Shopify payments app (part 2) |
| --- | --- | --- |
| Who owns checkout | The merchant's Magento; the module runs inside it | Shopify; the app is a separate service |
| Order creation | Order is created at Place Order in `pending_payment`, before payment | Shopify creates the order after the app resolves the payment session |
| Starting a payment | Module calls Tap during `initialize`, then redirects | Shopify POSTs a payment session to the app |
| Reporting the result | Module updates the order itself (transaction, invoice, state) | App calls `paymentSessionResolve` or `paymentSessionReject` |
| Session state | Checkout session (`last_real_order_id`) is used for the success page and cart restore | No storefront session; state is keyed by Shopify's payment id |
| Concurrency | Per-order DB lock plus a `pending_payment` guard | Compare-and-set state machine plus Shopify's per-id idempotency |
