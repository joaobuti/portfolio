# ADR-0002 — Money as integer minor units, with one rounding policy

- **Status:** accepted
- **Date:** 2026-08

## Context

Every amount in the system is currency. Proration divides those amounts by
arbitrary day counts, which means rounding happens constantly and in a place
customers can see.

The options were a float, a decimal string, a database `NUMERIC` read as a
string, or an integer count of minor units.

## Decision

`App\Support\Money`: an immutable value object holding an `int` of minor units
plus a `Currency` enum. Persisted as two columns — `amount_cents` and
`currency` — and rebuilt into a `Money` by a cast on read.

Rounding exists in exactly one method, `Money::prorate()`, and uses **half-up on
the exact rational**.

## Why

**Not a float.** `0.1 + 0.2 !== 0.3` is a curiosity in a tutorial and a
chargeback in a billing system. There is no amount of care that makes binary
floating point safe for money.

**Not a decimal string.** Correct, but every operation becomes a `bcmath` call
and the type system stops helping — `string` tells a reader nothing about
whether they are holding a price or a plan code.

**Integer minor units** are exact, comparable, sortable, sum correctly in SQL,
and cost nothing. The trade-off is a maximum representable amount around
9.2 × 10¹⁶ cents, which is not a constraint any subscription business will meet.

**Currency travels with the amount.** A bare integer column would let an
innocent `SUM(amount_cents)` add BRL to USD and produce a number that looks
plausible and is wrong. Pairing them means the invalid operation throws in PHP
instead of appearing in a report.

**One rounding policy, one method.** The failure mode being avoided is two call
sites rounding differently and disagreeing by a cent — which surfaces as an
invoice that does not reconcile and takes a day to find. Half-up rather than
banker's rounding because it is what payment gateways use and what an accountant
reviewing the invoice expects.

## Consequences

**Good**

- Exact arithmetic, no rounding surprises.
- Mixing currencies is a thrown exception, not a wrong number.
- `Money` serialises to `{ amount, currency, formatted }`, so clients do
  arithmetic on the integer and display the string without reimplementing our
  formatting rules in three front-ends.
- Pure PHP, so it is tested exhaustively in milliseconds.

**Bad**

- Two columns per amount instead of one.
- `Money::ofMajor()` takes a string, not a float. This surprises people once —
  and accepting a float would have thrown the precision away before the method
  ever ran, which is the whole point.
- Multiplying very large amounts by very large factors could overflow. Not
  guarded, because the amounts involved are off by fourteen orders of magnitude.

## Notes

Currencies with a minor unit scale other than 2 (JPY, KWD) are modelled — the
scale lives on the `Currency` enum — but only BRL, USD and EUR are enabled.
