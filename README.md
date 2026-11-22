# Portfolio Billing

[CI configuration](.gitlab-ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20)](composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-2a5d84)](phpstan.neon)

A subscription billing engine with trials, prorated plan-change calculations,
scheduled downgrades, cancellations, renewal invoicing and failed-payment
dunning. Payments support a deterministic fake gateway or InfinitePay hosted
checkout with server-verified asynchronous settlement. Initial proportional
invoicing, quoted upgrade adjustments and automatic pricing recovery follow the
approved financial policies; see
[current delivery status](docs/STATUS.md).

```
                  ┌──────────────┐
   HTTP  ────────▶│   API v1     │
                  │  Laravel 13  │
                  └──────┬───────┘
                         │  domain events
                  ┌──────▼───────┐        ┌─────────────┐
                  │  Services    │───────▶│    Redis    │
                  │  (business   │        │ queue·cache │
                  │   rules)     │        │   ·locks    │
                  └──────┬───────┘        └──────┬──────┘
                         │                       │
                  ┌──────▼───────┐        ┌──────▼──────┐
                  │  PostgreSQL  │        │   Worker    │
                  │              │◀───────│  renewals   │
                  └──────────────┘        └─────────────┘
                                          ┌─────────────┐
                                          │  Scheduler  │
                                          │ nightly 03h │
                                          └─────────────┘
```

## Run it

```bash
git clone <this-repo> && cd portfolio-billing
make up
```

That starts seven services: the API, Laravel queue worker, scheduler, pricing
service, Python risk worker, Postgres and Redis. The API runs migrations and
seeds a plan catalogue and demo customer. It does not create a login account.

For a local demo, create a user in Tinker:

```bash
make tinker
```

```php
App\Models\User::factory()->belongingTo(App\Models\Customer::where('email', 'demo@example.test')->firstOrFail())->create(['email' => 'demo-user@example.test']);
```

The development factory uses the password `password`. Obtain a token:

```bash
curl -X POST http://localhost:8000/api/v1/auth/tokens \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"email":"demo-user@example.test","password":"password","token_name":"local-demo","abilities":["plans:read"]}'
```

Copy `data.token` from the response into `BILLING_TOKEN`, then request plans:

```bash
export BILLING_TOKEN='<data.token>'
curl http://localhost:8000/api/v1/plans \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $BILLING_TOKEN"
```

```bash
make test          # the PHP suite (Unit, Arch and Feature)
make domain-check  # the pricing logic, with no Composer and no database
make entrypoint-check # startup and injected-key regression, in an isolated fixture
make check         # PHP style, static analysis, Rector and tests
```

The Node and Python checks run separately; see the
[pricing](services/pricing/README.md) and
[risk worker](services/risk-worker/README.md) instructions. CI also runs
coverage, dependency auditing and image builds; `make check` does not run those.

`make domain-check` is worth thirty seconds of your time: it runs the money and
proration logic with nothing but `php`, no vendor directory required. That it
*can* run that way is the architectural claim this project is making.

## What it does

| | |
|---|---|
| **Trials** | Plans can offer one. At trial end, the initial invoice is persisted and access waits for payment. |
| **Upgrades** | Apply immediately. The quoted difference, tax and coupon are invoiced separately. Exhausted retries or an unpaid deadline revert the upgrade. |
| **Downgrades** | Queued for the end of the paid period. The customer keeps what they paid for; nothing is refunded because nothing is owed. |
| **Preview** | `POST /api/v1/subscriptions/{uuid}/plan/preview` returns a proration quote with tax and optional coupon. It stores a `quote_id`, required by confirmation to preserve the quoted terms. |
| **Cancellation** | Defaults to the end of the period. Immediate cancellation is available and ends access at once. |
| **Renewal** | An hourly sweep queues one job per due subscription, with interval boundaries on day10. Retried jobs cannot double-advance a billing period. |
| **Audit** | Every plan change is recorded with the workings — days in period, days remaining, amount — so support can explain any charge. |

## API

`POST /api/v1/auth/tokens` is public. All other routes below require a bearer
token. Plan reads require `plans:read`; subscription reads and previews require
`subscriptions:read`; creation, changes and cancellation require
`subscriptions:write`. Subscription access also checks customer ownership.
The demo token above grants only plan reads.

| Method | Path | |
|---|---|---|
| `POST` | `/api/v1/auth/tokens` | Issue a token using an existing account's email and password |
| `GET` | `/api/v1/auth/me` | Current authenticated user |
| `DELETE` | `/api/v1/auth/tokens/current` | Revoke the current token |
| `GET` | `/api/v1/plans` | Active plans, cached in Redis, invalidated on write |
| `GET` | `/api/v1/plans/{code}` | One plan |
| `POST` | `/api/v1/subscriptions` | Open a subscription |
| `GET` | `/api/v1/subscriptions/{uuid}` | One subscription, with its change history |
| `POST` | `/api/v1/subscriptions/{uuid}/plan/preview` | What a plan change would cost |
| `PUT` | `/api/v1/subscriptions/{uuid}/plan` | Change plan |
| `DELETE` | `/api/v1/subscriptions/{uuid}` | Cancel |

Business rejections come back as a structured error rather than a bare 422:

```json
{
  "error": {
    "code": "subscription.plan_change_not_allowed",
    "message": "The subscription is already on this plan.",
    "context": { "reason": "same_plan" }
  }
}
```

Every response carries an `X-Correlation-Id`. Send your own and it is honoured,
propagated onto the queue, and written into every log line the request produces.

## Stack

**Billing core** — PHP 8.3 · Laravel 13 · PostgreSQL 17 · Redis 7 · Pest ·
PHPStan level max · Pint · Rector

**Pricing service** — Node 22 · TypeScript strict · zero runtime dependencies

**Risk worker** — Python 3.12 · mypy strict · ruff · pytest

Docker Compose · GitLab CI

## How the code is laid out

Laravel MVCS. Controllers are thin, services hold the business rules, models are
Eloquent, and the parts that are pure arithmetic — money, proration, the status
machine — depend on nothing but PHP.

```
app/
  Enums/         Currency, BillingInterval, SubscriptionStatus, PlanChangeType
  Support/       Money, Clock            ← no framework, unit tested in ms
  DTOs/          typed, serialisable input and output
  Services/      SubscriptionService, ProrationCalculator, PlanCatalog
  Models/        Eloquent, with casts, scopes and relations
  Http/          Controllers · FormRequests · Resources · Middleware
  Jobs/          queued, unique, idempotent
  Console/       the nightly sweep
  Events/        what other modules will listen to
```

[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) explains why, and
[`docs/adr/`](docs/adr) records the decisions with their trade-offs.

## Three guarantees, and how they are enforced

Getting billing wrong costs money and trust, so the important invariants are
each defended in more than one place.

**A customer cannot hold two subscriptions at once.** The service checks — and
because a check-then-write can be raced by two concurrent requests, a partial
unique index in Postgres refuses the second insert regardless.

**A subscription cannot be billed twice for the same period.** Renewal jobs are
unique while queued, the sweep takes a scheduler lock, and — the guarantee that
actually holds — `renew()` re-reads the row under a Redis lock and returns
untouched if the period already moved.

**A status cannot reach an impossible state.** The transition table lives on the
`SubscriptionStatus` enum and every change goes through one private method. No
service, job or controller compares statuses by hand.

## Tests

```
tests/Unit/      domain rules, no database, milliseconds
tests/Arch.php   boundaries enforced, not just documented
tests/Feature/   integration and API tests
```

Feature tests default to SQLite and an array cache in `phpunit.xml`. The CI
`feature` job runs them against Postgres and Redis through environment
overrides.

The architecture tests are the ones worth reading first. They assert that
`Money` and the enums never import `Illuminate`, that no `dd()` survives, that
DTOs are readonly, and that jobs implement `ShouldQueue`. A documented
convention decays; a failing pipeline does not.

## Roadmap

This is the first module of a larger system.

**Built**

- **Auth** — Sanctum tokens, ability scopes, ownership policies.
  [ADR-0006](docs/adr/0006-sanctum-tokens-abilities-and-ownership.md)
- **Pricing service (Node/TypeScript)** — regional tax and coupon rules on their
  own deploy cycle, called synchronously with timeout, circuit breaker and a
  degraded fallback. [ADR-0005](docs/adr/0005-synchronous-pricing-with-a-degraded-fallback.md)
- **Billing** — invoices, charge attempts, a scheduled dunning sweep, and a
  deterministic fake gateway so the stack runs with no third-party account.
  Initial invoices require payment before activation; upgrade adjustments and
  automatic pricing recovery follow [ADR-0011](docs/adr/0011-payment-activation-billing-day-and-adjustments.md).
  [ADR-0007](docs/adr/0007-invoices-charge-attempts-and-dunning.md)
- **Risk worker (Python)** — consumes `PaymentFailed` off a Redis list, scores
  delinquency risk, publishes back. At-least-once delivery, idempotency by
  message id, dead-lettering.
  [ADR-0008](docs/adr/0008-python-risk-worker-over-a-redis-list.md)

- **InfinitePay** — per-invoice hosted checkout, verified settlement and scoped
  invoice APIs. [ADR-0009](docs/adr/0009-infinitepay-hosted-checkout.md)
- **OpenAPI** — the [complete API v1 contract](docs/openapi.json) for generated clients.

- **Operational metrics** — staff-only Prometheus gauges for billing states and
  payment-verification backlog. [Operations guide](docs/OPERATIONS.md)

**Next**

- **Dashboard (React/TypeScript)** — one page, typed client generated from the
  [OpenAPI contract](docs/openapi.json).

## License

MIT

## Container startup

The development API completes migrations and demo seeding before its health
check allows the Laravel worker and scheduler to start. Repeated local seeding
creates missing demo records and preserves existing catalogue edits.

The production image excludes local `.env`, host `vendor`, Git metadata and
tests. Supply `APP_KEY` and database/Redis settings at runtime. Startup caches
that resolved configuration and runs migrations without demo seeding. It never
generates a production key. Existing customer/account provisioning remains the
responsibility described in ADR-0006.

## InfinitePay checkout

The billing API remains Laravel; pricing is Node/TypeScript and risk processing
is Python. The InfinitePay adapter runs natively in Laravel, using the same
merchant account as an existing integration if desired. See
[ADR-0009](docs/adr/0009-infinitepay-hosted-checkout.md) and the complete
[OpenAPI 3.1 contract](docs/openapi.json).

Configure these settings through the server environment or secret manager:

```dotenv
BILLING_GATEWAY=infinitepay
INFINITEPAY_HANDLE=your-infinite-tag-without-dollar-sign
INFINITEPAY_API_KEY=your-server-side-api-key
INFINITEPAY_WEBHOOK_URL=https://your-domain/api/v1/webhooks/infinitepay
INFINITEPAY_REDIRECT_URL=https://your-frontend/payment-return
```

`INFINITEPAY_REDIRECT_URL` is optional. The webhook must be a public HTTPS URL
for this project. Rebuild Laravel's configuration cache after changing settings
and restart queue workers. Run the application migrations before activating
the adapter; no live activation is bundled with this change. Keep the existing
queue worker and scheduler running: payment verification uses the configured
billing queue, and `checkouts:verify` recovers pending notifications every minute.

The API key is required for single-payment links and their expiry even if an
older integration works with only an InfiniteTag. In JBoot, the account handle
comes from the payments database's `provider_connections.public_identifier`,
not its environment template. A stored `secret_ref` is a reference to retrieve
securely, not an API key to paste into source or chat. This implementation does
not migrate or dump JBoot's database.

| Operation | Token ability | Purpose |
| --- | --- | --- |
| `GET /api/v1/subscriptions/{uuid}/invoices` | `invoices:read` | Owned invoices, 20 per page |
| `GET /api/v1/invoices/{uuid}` | `invoices:read` | Invoice amounts and current checkout state |
| `POST /api/v1/invoices/{uuid}/checkout` | `invoices:write` | Create or retrieve the one stored checkout intent |
| `POST /api/v1/invoices/{uuid}/payment-check` | `invoices:write` | Submit redirect identifiers for asynchronous verification |
| `POST /api/v1/webhooks/infinitepay` | Public | Persist unsigned identifiers for server verification |

Customer ownership is checked in addition to token abilities. Existing tokens
need reissuing to gain the invoice abilities. Checkout and payment-check respond
with 202; inspect invoice state afterwards rather than treating the response
as payment approval. Submit `transaction_nsu` and either `slug` or
`invoice_slug`; the public webhook also requires `order_nsu`. The authenticated
endpoint takes the order from the invoice and can retry an exhausted signal.

Link creation requires a due, open, non-degraded BRL invoice of at least R$1.
A zero invoice still settles locally. Links expire after seven days by default.
Unknown, failed and expired intents are kept for reconciliation and are not
replaced automatically. Verified late payments can still settle the invoice.
This flow supports Pix/card payments per invoice; it does not debit saved cards
on a recurring schedule. Refunds remain excluded. The approved financial policies are described in
[ADR-0011](docs/adr/0011-payment-activation-billing-day-and-adjustments.md).

Tests use Laravel HTTP fakes; they do not create real links or payments.
The shipped default remains `BILLING_GATEWAY=fake` until securely configured.

## Monitoring

`GET /api/v1/metrics` exposes read-only operational gauges for staff tokens
with `metrics:read`. The global snapshot is cached for 15 seconds and contains
only bounded status labels. Customers cannot access it, including with a
manually granted scope. See the [operations guide](docs/OPERATIONS.md) for
metric definitions, credential rotation and a Prometheus scrape example.
No external monitoring service or alert delivery is configured by this change.

## Payment and billing rules

Without a free trial, the first invoice is due immediately and proportional to
whole days until the interval boundary on day10 in America/Sao_Paulo. Access
stays blocked as `pending_payment` until initial settlement. Subsequent payment
boundaries use day10; existing monthly/quarterly/yearly cadence is retained.
Free trials retain their existing access window and wait for payment afterwards.

Plan confirmation now requires the `quote_id` from the preview endpoint. Send
that identifier in `PUT /api/v1/subscriptions/{uuid}/plan` to preserve quoted
amounts, taxes, coupon and immediate/scheduled mode. Upgrades create a separate
adjustment invoice and revert only after retries are exhausted or the payment
deadline expires. The invoice response exposes `kind` and `payment_deadline_at`.
Only one unresolved adjustment is allowed at a time.

The scheduler automatically retries degraded pricing with current rules and
persisted backoff. Keep the billing worker and scheduler running: collection,
`invoices:reprice-degraded` and `invoices:expire-adjustments` sweep every minute.
Apply migrations before updating workers. `BILLING_DUE_IN_DAYS` is superseded by
the billed period boundary. See ADR-0011 for zero-balance and late-payment rules.
