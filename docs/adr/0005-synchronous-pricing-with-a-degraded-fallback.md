# ADR-0005: Pricing is a synchronous call with a degraded fallback

**Amendment (2026-11-22):** [ADR-0011](0011-payment-activation-billing-day-and-adjustments.md)
resolves the historical initial/adjustment/repricing gaps noted below and
defines the approved payment calendar and rollback policy.

**Status** Accepted
**Date** 2026-09-01

**Implementation note (2026-11-21):** Billing now persists invoice subtotal,
discount, tax and total (ADR-0007). The historical limitation about unpersisted
tax below still applies to plan-change adjustments, whose collection is not
connected. See [current delivery status](../STATUS.md).

## Context

Regional tax and coupon rules change on a different cadence to the billing
core. A legislature changes a rate; marketing launches a coupon. Neither should
require a deploy of the code that renews subscriptions, and neither should be
able to break a renewal by being wrong.

That argues for a separate service. It does not, by itself, say how the billing
core should talk to it.

The call has an awkward shape: the answer is needed **while a customer is
waiting** for a plan-change preview. An asynchronous message would be more
robust and completely useless — there is nothing to show the customer until the
tax is known.

## Decision

**The pricing service is called synchronously, over HTTP, and a failure
produces a degraded quote rather than an exception.**

Four parts:

1. **A short client timeout (2s), shorter than the service's own (5s).**
   We decide what happens on a slow dependency, rather than inheriting whatever
   it decides.

2. **A circuit breaker in Redis, shared by every container.**
   After five consecutive transport failures the circuit opens for thirty
   seconds and calls skip the network entirely. This is the part that prevents
   an outage: without it, a dependency that has started timing out costs every
   plan change the full timeout, and the pile of waiting workers is what
   actually takes the site down. Per-process breakers were rejected because
   eight containers would hammer a sick dependency eight times as hard before
   any of them concluded it was down.

3. **A degraded quote as the fallback**: the subtotal, untaxed, undiscounted,
   with `degraded: true`. Never an estimate from a stale cache — a customer
   charged an amount that no rule in the system can reproduce six months later
   is a reconciliation problem far worse than a missing tax line.

4. **The flag, not an exception, decides what happens next.**
   `PriceQuote::isDegraded()` is surfaced all the way to the API response. A
   preview shows the provisional number and says so; a path that moves money is
   expected to refuse. Only the caller knows which it is, so only the caller
   decides.

### Failures that trip the breaker, and failures that do not

A timeout or a 5xx means the dependency is unhealthy: counted. A 4xx means the
dependency is perfectly healthy and is telling us our request is malformed —
that is our bug, logged loudly, breaker left closed. Counting it would let one
bad deploy of ours black out a service that never faltered.

A **rejected coupon is not a failure at all.** It comes back as a normal 200
with `applied: false` and a reason. If a bad code were a 4xx, a customer
guessing coupon codes would look exactly like a broken dependency.

### The response must reconcile

`subtotal - discount + tax === total` is asserted on every response before it is
trusted. A quote whose numbers do not add up is treated as a transport failure
and degraded. Every field can be a valid integer and the total still be wrong;
that is precisely the failure that would otherwise become a charge nobody can
explain.

### Rounding is transcribed, not reinvented

The service's `applyRate()` is a deliberate transcription of PHP's
`Money::prorate()` — half-up away from zero — with the PHP test cases copied
into `money.test.ts`. Proration is computed in the core and tax in the service;
if the two round differently, the preview disagrees with the charge by a cent,
which is invisible in testing and expensive in production.

The service's arithmetic runs in `BigInt`. A large invoice in cents multiplied
by a basis-point rate leaves the float53 safe range sooner than people expect.

## Consequences

**Good**

- A pricing outage degrades one number instead of blocking plan changes.
- The rules deploy independently of the billing core.
- The service has **zero runtime dependencies**, so its production image is the
  base image plus compiled JavaScript — nothing to audit on a CVE Friday.
- `PRICING_SERVICE_ENABLED=false` gives a working stack with no Node container,
  and makes the test suite exercise the degraded path by default rather than
  leaving it as the branch nobody runs until an outage.

**Bad, and accepted**

- A plan-change preview now depends on a network call. Mitigated by the
  breaker, but the p99 of that endpoint is worse than it was.
- The breaker is not distributed-consensus. Two containers can race and both
  send a probe. The cost is one extra request; the alternative is a lock in the
  path of every quote.
- **Tax is not yet persisted.** `subscription_changes` records the proration
  amount only. Storing tax and discount belongs with the Billing module, where
  there is an invoice to attach them to — adding columns now would mean a
  schema for a charge that does not exist.

**Revisit if**

- Plan changes become high-volume enough that the p99 matters more than the
  freshness of a rate. Then quotes get cached by (region, amount, coupon) with
  a short TTL, and the cache becomes the fallback instead of the untaxed
  subtotal.
- A second consumer needs pricing. Then the contract is worth pinning with a
  schema shared by both sides rather than hand-validated at the edge.
