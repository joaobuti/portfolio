# ADR-0004 — Upgrades bill now, downgrades wait

**Amendment (2026-11-22):** [ADR-0011](0011-payment-activation-billing-day-and-adjustments.md)
resolves the historical initial/adjustment/repricing gaps noted below and
defines the approved payment calendar and rollback policy.

- **Status:** accepted
- **Date:** 2026-08

**Implementation note (2026-11-21):** Immediate plan changes and proration
records are implemented, but collection of their monetary adjustment is not
connected. The preview is a quote, not a reserved final charge. See
[current delivery status](../STATUS.md) for the remaining integration decisions.

## Context

A customer on a R$ 29,90 plan moves to a R$ 99,90 plan on day 16 of a 30-day
period. What do we charge, and when does the change take effect?

This is a commercial decision as much as a technical one, and it has to be
written down because the answer is arbitrary enough that two engineers will
guess differently.

## Decision

**Upgrades apply immediately.** The unused portion of the current plan is
credited, the new plan is charged for the days that remain, and the customer
pays the difference. In the example: credit R$ 14,95, charge R$ 49,95, due
R$ 35,00.

**Downgrades are queued for the end of the current period.** Nothing is charged
and nothing is refunded; the customer keeps the plan they paid for until it runs
out, and `pending_plan_id` records what happens next. Support can override with
`apply_immediately`, which credits the difference.

**Proration is by whole remaining days.** A customer who upgrades at 23:50 is
billed as though they upgraded at 00:00.

**A credit may be negative.** The calculator returns it; deciding what to do
with it is Billing's problem, not Subscriptions'.

## Why

**Upgrades now** — the customer asked for more product and expects it
immediately. Delaying it to the next period is the kind of thing that makes
people cancel instead of upgrade.

**Downgrades later** — they have already paid for the more expensive plan.
Applying it immediately means either taking away service they paid for, or
issuing a refund we do not owe. Waiting costs nothing and avoids both. It is
also what Stripe, Chargebee and every mature billing product do, which matters:
a customer who has used another SaaS already has this expectation.

**Whole days** — sub-day proration is easy to implement and impossible to
explain on an invoice. Rounding the partial day in the customer's favour costs
cents and prevents a category of support ticket.

**Half-up rounding** — see [ADR-0002](0002-money-as-integer-minor-units.md).

## Consequences

**Good**

- The preview endpoint and the real change share one code path, so the number
  shown is provably the number billed.
- Every change is written to `subscription_changes` with its workings — days in
  period, days remaining, amount — so any charge can be explained without
  re-running the calculation.
- The policy is one class with no dependencies, so changing it is a contained
  edit with fast tests.

**Bad**

- A customer who downgrades and then upgrades again inside the same period gets
  the queued downgrade silently dropped by the upgrade. Correct, but not obvious
  from the API; the change history is the only place it shows.
- Whole-day proration slightly under-charges. At the amounts involved this is
  cents per event and cheaper than the support cost of explaining hours.

**Revisit if**

- Annual plans grow enough that a day of an annual plan is material.
- Usage-based pricing arrives, at which point "days remaining" stops being the
  right denominator.
