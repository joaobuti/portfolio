# ADR-0007: Invoices, charge attempts, and a dunning policy the enum owns

**Amendment (2026-11-22):** [ADR-0011](0011-payment-activation-billing-day-and-adjustments.md)
resolves the historical initial/adjustment/repricing gaps noted below and
defines the approved payment calendar and rollback policy.

**Status** Accepted
**Date** 2026-09-01

**Amendment (2026-11-22):** [ADR-0009](0009-infinitepay-hosted-checkout.md)
adds the approved InfinitePay hosted adapter. The synchronous fake contract and
its dunning remain; asynchronous links are not card declines.

**Implementation note (2026-11-21):** The implemented flow connects invoicing
to renewals. Initial invoicing without a trial, plan-change adjustment
collection and recovery of degraded invoices remain incomplete. The decisions
below describe the intended design; see [current delivery status](../STATUS.md)
for the implementation gaps.

## Context

Two things were waiting on this module.

`SubscriptionService::markPastDue()` and `markActive()` had **no callers at
all** — dead code since the first commit, waiting for something to detect a
failed charge. `SubscriptionStatus::PastDue` and the whole dunning window were
modelled and unreachable.

And the pricing service computed tax that was shown in a preview and thrown
away, because there was nothing to attach it to (ADR-0005 says so explicitly).

Meanwhile the Python risk worker has been blocked since the roadmap was
written: it consumes `PaymentFailed`, and nothing produced that event.

## Decision

**Two tables, one gateway abstraction, and a dunning policy that lives in the
failure-reason enum.**

### Invoices are issued by an event, not by the renewal itself

`SubscriptionRenewed` → `IssueInvoiceOnRenewal` → `BillingService`. Renewing is
about what a subscription *is*; invoicing is about what it *costs*. The
dependency points one way — billing knows about subscriptions, subscriptions
know nothing about billing — which is what keeps the renewal tests free of a
payment gateway.

The listener is `$afterCommit`. Without it a worker can read the *old*
`current_period_start` before the renewal transaction commits and invoice a
period that was already billed.

### Double-billing is prevented by a unique index, not by a check

`unique(subscription_id, period_start)`. Any check written in PHP loses to two
workers racing. `issueForPeriod()` catches the violation and returns the
existing invoice, because the desired end state is "an invoice exists for this
period" and it does.

### Duplicate charges are prevented in three places

Belt, braces, and a second pair of braces — this is the most expensive bug the
module can ship:

1. A distributed lock around `collect()`.
2. The status re-read **inside** the lock, since it may have changed while we
   waited.
3. An idempotency key derived from the invoice and attempt number, passed to
   the gateway, so even a duplicate call cannot take money twice at the vendor.

### The retry policy belongs to the failure reason

`PaymentFailureReason::isWorthRetrying()`. An expired card retried eight times
is eight guaranteed failures, eight gateway fees, and a customer nobody told to
update their card. Modelling the reason rather than storing the gateway's
string is what makes that decision possible at all — every gateway spells its
decline codes differently, and dunning turns on the *kind* of failure.

The raw gateway code and message are kept alongside, because support eventually
needs the vendor's own words and a mapping bug is only findable if the input
survived.

### The sweep reads a decision; it does not re-derive one

`invoices.next_attempt_at` is written by `BillingService` at the moment an
attempt fails — the one place that knows the failure reason and the attempt
count. `invoices:collect` then selects on that column and nothing else, so the
whole sweep is one covered index scan.

A sweep that recomputed the retry schedule would be a second implementation of
the same policy, and the two would drift the first time someone changed
`retry_days` and updated only one of them. A null means "never again", which is
what makes a paid or written-off invoice unreachable by the sweep for two
independent reasons rather than one.

The command runs at 04:00, an hour after renewals. A renewal issues an invoice
whose first collection job is queued by the listener, so by the time the sweep
runs, today's invoices have already been tried once and it is picking up
retries — which is what it is for.

### Queue retries are not the dunning schedule

`CollectInvoice` has `tries = 3` with a 15-second backoff, for infrastructure
faults. A declined card is not a fault; it is an answer, and it never reaches
the queue's retry logic. Conflating the two retries a declined card three times
in ninety seconds — three gateway fees and no new information.

### A degraded invoice is never charged

If the pricing service was unreachable when the amount was worked out, the
invoice carries no tax and `pricing_degraded` is true. Charging it would bill an
amount no rule in the system can reproduce. This is the flag ADR-0005 promised
would eventually have a consumer that refuses.

The flag is persisted rather than inferred, because "was this taxed?" cannot be
answered later from a zero tax column — a zero-rated region also produces zero.

### A zero-value invoice settles without touching the gateway

A zero-value charge is a fee for nothing, and some gateways reject it outright
— which would look like a payment failure and start dunning a customer who owes
us nothing.

### The fake gateway ships in the application, not only in the tests

The whole stack has to run for someone who has just cloned the repository. Its
outcome is derived from the amount rather than randomly, so the unhappy paths
are reachable on demand and the suite is not flaky: amounts ending `01`
decline, `02` insufficient funds, `03` expired card, `04` network error. The
same convention real gateways use for test cards, for the same reason.

## What this unblocks

- **`markPastDue()` and `markActive()` finally have callers.** A subscription
  enters dunning on the first failure and returns to active when a later
  invoice is paid. Past due still grants access — cutting a paying customer off
  on the first failed charge is how a card expiry becomes churn.
- **Tax is persisted.** The four amount columns are stored, not recomputed: the
  rate that applied on the day is a fact, and next year's rate must not silently
  rewrite last year's invoice.
- **`PaymentFailed` exists.** The Python risk worker is no longer blocked.

Its shape was chosen for that consumer: `attemptNumber` is carried rather than
derived, so the worker never has to query back into Postgres and couple itself
to our schema; and the reason is the mapped enum, not a vendor string, so
swapping gateways is not a change in two repositories.

## Consequences

**Good**

- The billing cycle is closed end to end, with no third-party account needed.
- Dunning policy is one config array and one enum method.

**Bad, and accepted**

- `BillingService` depends on `SubscriptionService`. The alternative — another
  event hop for two status changes — would spread one decision across three
  files to avoid an arrow that genuinely points that way.
- Dunning has no per-customer backoff. A customer with five failing
  subscriptions gets five gateway calls on the same day. Fixing that needs a
  notion of a customer-level payment method, which does not exist yet.

**Deliberately absent**

- **Refunds and credit notes.** A reversal is not a negative invoice, and
  guessing at the model before there is a requirement would be inventing one.
- **Partial payments.** Every invoice here is paid in full or not at all.
- **Other real gateway adapters.** InfinitePay hosted checkout is covered by
  ADR-0009; additional providers are outside the current scope.
