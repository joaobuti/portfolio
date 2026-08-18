# Architecture

For the reader with fifteen minutes who wants to know whether this codebase was
thought about.

## The shape

Laravel MVCS — Model, View (here: API Resource), Controller, Service — with one
addition that does most of the work: **the parts of the domain that are pure
arithmetic have no framework in them at all.**

```
                    HTTP
                     │
              FormRequest          validation, nothing else
                     │
              Controller           translate, delegate, respond
                     │
                    DTO            typed, immutable, serialisable
                     │
    ┌────────────────▼──────────────────┐
    │        SubscriptionService        │  the only place a subscription
    │  transactions · locks · events    │  changes state
    └────┬──────────────────┬───────────┘
         │                  │
   ProrationCalculator    Models         Eloquent, casts, scopes
   Money · Enums            │
   ── no framework ──   PostgreSQL
```

`ProrationCalculator` takes five arguments and returns a value object. It has no
constructor dependencies, reads no clock, touches no database and imports
nothing from `Illuminate`. That is why the pricing rules — the part where a bug
costs real money — are tested in milliseconds without a container, and why
`make domain-check` can run them with nothing but `php`.

Everything else is ordinary, boring Laravel, on purpose.

## Why MVCS and not hexagonal

A hexagonal or DDD-tactical layout would have produced repository interfaces,
adapters and mappers around a domain that has one aggregate. Three of those
layers would have had exactly one implementation each and no plausible second
one — which is coupling wearing the costume of decoupling.

What actually mattered was that the business rules live in one place, that they
are testable without infrastructure, and that a Laravel developer can find
anything in this repository in ten seconds. MVCS gives all three.

The one boundary worth paying for is drawn: pure domain arithmetic on one side,
framework on the other. It is enforced by an architecture test rather than by a
folder name. See [ADR-0001](adr/0001-mvcs-over-hexagonal.md).

## Invariants and where they are defended

Billing bugs are expensive and quiet. The three that matter are each guarded in
more than one layer, because any single guard has a failure mode.

### One access-granting subscription per customer

| Layer | Guard | Fails how |
|---|---|---|
| Service | `activeSubscriptions()->exists()` before insert | Two concurrent requests can both pass the read |
| Database | Partial unique index on `(customer_id) WHERE status IN (...)` | Cannot be raced |

The service check exists for the error message; the index exists for
correctness. Neither is redundant.

### A period is never billed twice

| Layer | Guard | Fails how |
|---|---|---|
| Scheduler | `withoutOverlapping()` + `onOneServer()` | Lock store outage |
| Queue | `ShouldBeUnique` | Uniqueness lock expires (`uniqueFor`) |
| Service | re-read under a Redis lock, no-op if the period already moved | This one holds |

The first two reduce noise. The third is the actual guarantee — which is why
`renew()` is idempotent by construction rather than relying on the queue.

### No impossible status

The transition table is a method on `SubscriptionStatus`. `SubscriptionService`
has exactly one private `transitionTo()` and never compares statuses by hand. An
illegal transition throws a typed exception carrying the list of transitions
that *were* allowed, so the API response tells the client what to do instead.

## Money

Never a float. Every amount is an integer in the currency's minor unit, paired
with its currency, and the pair is stored in two columns and rebuilt into a
`Money` on read. Adding BRL to USD throws.

Rounding happens in exactly one method — `Money::prorate()` — using half-up on
the exact rational. One place to read, one place to change, and no chance of two
call sites disagreeing about a cent. See [ADR-0002](adr/0002-money-as-integer-minor-units.md).

## Time

Time enters the application through a single binding: `App\Support\Clock`.
Services depend on it, the pure calculator receives moments as arguments, and
tests pin the clock with one helper. No global state to freeze and no travelling
back afterwards.

Month arithmetic is hand-rolled for one reason: `modify('+1 month')` turns
January 31st into March 3rd, which skips a February invoice and moves a
customer's anniversary date permanently. `BillingInterval::advance()` clamps to
the last valid day instead, and there is a test for every boundary that has ever
bitten a billing system.

## Redis, doing three jobs

Queue, cache and distributed lock — deliberately distinguished, because they
have different failure modes and different consequences.

- **Queue** — renewal and observability work, on separate named queues so a
  backlog of audit logging cannot delay billing.
- **Cache** — the plan catalogue, invalidated by a model observer on write
  rather than expired by TTL. A TTL means either serving a stale price or
  hammering the database; an observer means neither.
- **Lock** — serialising mutations of a single subscription across web nodes and
  workers alike.

See [ADR-0003](adr/0003-redis-for-queue-cache-and-locks.md).

## Errors

Every business rejection extends `App\Exceptions\DomainException` and carries a
stable machine-readable code, an HTTP status and a context payload. One rule in
`bootstrap/app.php` renders all of them. Adding a new failure mode never means
touching the HTTP layer, and API clients branch on `error.code` rather than
string-matching a message that might be translated tomorrow.

## Observability

A correlation id is generated (or accepted) at the edge, stored in Laravel's
`Context`, and therefore carried automatically onto every queued job and into
every log line. Lifecycle events go to a separate JSON-formatted `activity`
channel, so an audit trail is searchable by `subscription_uuid` as a field
rather than as a substring buried in prose.

This is deliberately built now, before the Python worker exists, because a
correlation id retrofitted across three languages is a migration and a
correlation id designed in is a header.

## What is deliberately absent

- **CQRS and Event Sourcing.** The read and write models are the same shape and
  the audit trail already answers "what happened". Replay would cost more than
  it returns.
- **A generic repository.** Eloquent is already the repository. Wrapping it to
  swap it for something that will never arrive is ceremony.
- **Auth.** It belongs to its own module. `authorize()` returning `true` with a
  comment saying so is more honest than a permission check that checks nothing.
- **A baseline file for PHPStan.** A baseline is a promise to fix things later
  that nobody keeps. This starts at level max.
