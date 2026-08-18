# Portfolio Billing

[![pipeline](https://img.shields.io/badge/pipeline-passing-brightgreen)](.gitlab-ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20)](composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-2a5d84)](phpstan.neon)

A subscription billing engine. It handles the parts of recurring revenue that
are easy to describe and hard to get right: free trials that convert, mid-cycle
upgrades charged pro rata, downgrades that wait for the period the customer
already paid for, cancellations, failed-payment dunning, and a renewal cycle
that runs every night without ever billing anyone twice.

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

That builds five containers, waits for Postgres to accept connections, runs the
migrations, seeds a plan catalogue, and starts the API, a queue worker and the
scheduler. When it settles:

```bash
curl http://localhost:8000/api/v1/plans
```

```bash
make test          # the full suite
make domain-check  # the pricing logic, with no Composer and no database
make check         # everything CI runs
```

`make domain-check` is worth thirty seconds of your time: it runs the money and
proration logic with nothing but `php`, no vendor directory required. That it
*can* run that way is the architectural claim this project is making.

## What it does

| | |
|---|---|
| **Trials** | Plans can offer one. The subscription bills for the first time on the day the trial ends, not before. |
| **Upgrades** | Apply immediately. The unused part of the current plan is credited, the new plan is charged for the days that remain, and the customer pays the difference. |
| **Downgrades** | Queued for the end of the paid period. The customer keeps what they paid for; nothing is refunded because nothing is owed. |
| **Preview** | `POST /subscriptions/{id}/plan/preview` returns the exact number a change would charge, through the same code path that would charge it. |
| **Cancellation** | Defaults to the end of the period. Immediate cancellation is available and ends access at once. |
| **Renewal** | A nightly sweep queues one idempotent job per due subscription. Retried jobs cannot double-advance a billing period. |
| **Audit** | Every plan change is recorded with the workings — days in period, days remaining, amount — so support can explain any charge. |

## API

| Method | Path | |
|---|---|---|
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

PHP 8.3 · Laravel 13 · PostgreSQL 17 · Redis 7 · Pest · PHPStan level max ·
Pint · Rector · Docker Compose · GitLab CI

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
tests/Feature/   the real thing against Postgres and Redis
```

The architecture tests are the ones worth reading first. They assert that
`Money` and the enums never import `Illuminate`, that no `dd()` survives, that
DTOs are readonly, and that jobs implement `ShouldQueue`. A documented
convention decays; a failing pipeline does not.

## Roadmap

This is the first module of a larger system. Next:

- **Billing** — invoices, charge attempts, dunning, a gateway adapter with a
  local fake so the stack still runs with no third-party account
- **Risk worker (Python)** — consumes `PaymentFailed` from Redis, scores
  delinquency risk, publishes back. Retries, DLQ, idempotency by message id.
- **Pricing service (Node/TypeScript)** — regional tax and coupon rules on their
  own deploy cycle, called synchronously with timeout, circuit breaker and a
  degraded fallback
- **Dashboard (React/TypeScript)** — one page, typed client generated from the
  OpenAPI contract
- **Auth**, **OpenAPI**, **metrics**

## License

MIT
