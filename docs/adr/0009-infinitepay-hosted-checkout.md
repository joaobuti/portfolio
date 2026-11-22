# ADR-0009: InfinitePay hosted checkout and verified settlement

**Amendment (2026-11-22):** [ADR-0011](0011-payment-activation-billing-day-and-adjustments.md)
resolves the historical initial/adjustment/repricing gaps noted below and
defines the approved payment calendar and rollback policy.

- Status: accepted by the project owner, 2026-11-22
- Supersedes: ADR-0007's exclusion of real payment adapters only

## Decision

Implement the approved InfinitePay flow in the Laravel billing service. This
project remains multistack: Node/TypeScript owns pricing and Python owns risk.
The existing JBoot adapter is Go; it supplies an integration reference, rather
than a runtime dependency or code to copy into PHP.

Each issued, due, open, non-degraded invoice can have one hosted checkout.
`BILLING_GATEWAY=fake` preserves the synchronous local simulation;
`infinitepay` selects asynchronous links. A pending link is not a successful
charge or a declined card. Creating links never increments failed attempts or
starts dunning. Zero totals keep the existing local settlement path. Positive
InfinitePay totals must be BRL and at least 100 cents.

Persist an immutable intent before calling `/links`: invoice, UUID, order,
merchant handle, amount/currency, callback/return URLs and expiry. The order is
namespaced `pb-{uuid}` to coexist with JBoot orders on the same account. Links
request `single_payment=true` and `valid_until`, with a default seven-day life.
These options require an API key even if a legacy handle-only integration
already works. Keys stay in server environment configuration and never enter
checkout rows, public resources, events or error logs.

There is no documented unconditional idempotency guarantee for repeated link
creation. A crash, timeout, unexpected response or malformed URL leaves a
`creation_unknown` intent. A definitive 4xx refusal becomes `creation_failed`.
Neither is recreated automatically. Existing, failed or expired intents are
returned on subsequent calls. Recovery/replacement requires reconciliation;
there is no endpoint that deletes an intent and silently issues a second link.
Provider requests use a fixed official HTTPS endpoint, no HTTP redirects and
bounded timeouts; returned payment URLs must use an allowed InfinitePay host.

## Confirmation and recovery

The public webhook is unsigned. It accepts bounded identifiers only; client
amounts, merchant fields and `paid` flags have no authority. The same inbox
accepts authenticated redirect identifiers. Unknown orders return the same
202 acknowledgement without creating rows or dispatching work.

A durable notification row precedes queue dispatch. A unique verification job
calls `/payment_check` with the stored merchant and order, plus the submitted
transaction and slug. Scheduled `checkouts:verify` runs every minute to recover
lost dispatches. Unavailable or unpaid responses receive bounded exponential
backoff; after twelve checks the signal enters `reconciliation`. A repeated
public webhook does not reset that budget. An authorized payment-check request
may explicitly restart an exhausted signal after an outage is resolved.

Settlement requires strict provider approval, integer fields, matching original
amount/currency, paid amount at least the original amount, and supported Pix
or credit-card capture/installments. Paid amount may exceed the invoice due to
installment fees; it does not change the invoice total. A transaction NSU can
settle only one checkout. Invoice locking plus an atomic database transaction
protect the invoice, checkout, successful charge audit and subscription
reactivation. Repeated confirmation produces one audit and one `InvoicePaid`
event. A crash while finishing the inbox can safely replay settlement.

Local expiry hides the payment URL; it does not discard a late, verified
payment. A different second payment, a mismatched amount, an already voided
invoice or transaction reuse stays in reconciliation rather than being silently
credited elsewhere. Underpayments, refunds and overpayment accounting remain
outside this implementation.

## Configuration source and activation

In JBoot, `provider_connections.public_identifier` in its payments PostgreSQL
database supplies the InfiniteTag for a verified InfinitePay connection. An
optional `secret_ref` points to a secret store; it is not the secret itself.
`PAYMENTS_INFINITEPAY_BASE_URL` and `PAYMENTS_PUBLIC_BASE_URL` configure its
Go adapter, and do not identify the account. No actual merchant handle or API
key was found in the inspected tracked configuration or environment templates.
No private database dump or production service startup was used to extract one.

Reuse that merchant account by configuring this project's own server settings
as described in the README. JBoot's webhook URL must not be copied: each link
must route to this project's webhook. The API key must allow single-payment
and expiry options. No live link, payment or production migration was performed
as part of implementation. Default configuration remains `fake` until the
merchant, key and public callback are supplied securely.

## Boundaries

This is one hosted payment per invoice/cycle, not automatic recurring debit.
Initial-invoice activation, plan-change adjustment invoicing/failure and
repricing degraded invoices retain their pending decisions in STATUS.md.
Metrics and a React dashboard are separate planned work. Independent token
abilities `invoices:read` and `invoices:write` and customer ownership guard the
invoice API. Previously issued tokens must be reissued with these abilities.

## Contract and evidence

- [Official Checkout documentation](https://www.infinitepay.io/checkout-documentacao), checked 2026-11-22.
- JBoot reference: `implementations/svc-jboot-payments/internal/adapters/infinitepay/client.go` and `docs/architecture/decisions/012-infinitepay-pilot.md` in `/home/projetos/jboot`.
- API: [OpenAPI 3.1 contract](../openapi.json), all current `/api/v1` operations.
- Mocked HTTP, PostgreSQL and Redis regressions: `InfinitePayCheckoutTest`,
  `InvoiceEndpointsTest`, `OpenApiContractTest`.
- [Execution record](../tasks/2026-11-22-infinitepay-checkout.json).
