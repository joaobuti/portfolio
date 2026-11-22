# ADR-0011: Activation after payment, day10 billing and reversible upgrades

- Status: accepted by the owner, 2026-11-22
- Supersedes the three financial gaps previously listed in STATUS.md

## Owner decisions

1. Without a free trial, access starts only after payment. The first payment
   is immediate and proportional; subsequent billing boundaries are day10.
2. Preserve the upgrade's quoted terms, including tax and coupon. Revert it
   only when payment retries are exhausted or its payment deadline is reached.
3. Automatically recover degraded invoices, applying pricing rules current at
   the time of recovery.

Existing free trials remain the exception already defined before this decision:
the original activation question concerned subscriptions without a trial. At
trial end, the first paid period also waits for settlement before granting
access. A zero-balance invoice uses the existing local settlement rule and
creates no fictitious payment attempt.

## Calendar and initial activation

The calendar uses **America/Sao_Paulo**, with boundaries at midnight on day10,
stored as UTC. Monthly, quarterly and yearly intervals retain their existing
cadence, with the payment day aligned to10. The initial amount uses whole local
remaining days divided by whole days in the corresponding full anchored
interval, with Money's existing half-up integer rounding. For a monthly R$100
plan starting June16, the initial June16–July10 invoice is R$80 (24/30 days).
Starting on day10 charges the full next interval. Sub-day proration remains whole-day based. Upgrades within that shortened
first period use the full anchored interval as the denominator: upgrading
R$100 to R$200 on June16 adds R$80, not R$100. All dates in that calculation
use the same Sao Paulo timezone, including when UTC has already crossed
midnight. Legacy off-anchor history retains its original calculation dates.

A non-trial subscription begins as `pending_payment`, which grants no access
and is not renewable. Its initial invoice is issued atomically with creation;
a customer cannot create another pending/active/trial/dunning subscription.
Both the customer mutation lock and partial database unique index enforce this.
Cancelling an unpaid pending subscription ends it immediately, since there is
no paid access period to retain.

Only settlement of its **initial** invoice activates a pending subscription.
Payment of an adjustment cannot activate or clear delinquency for an unpaid
regular period. A failed first payment leaves access blocked. Trials retain
access until their trial ends, then also enter pending payment.

Renewal uses a synchronous `SubscriptionBillingPeriodStarted` event to create
the invoice in the same transaction as the new period. The queued renewal event
is published only after the subscription lock is released. It may enqueue
collection/audit; if publication fails, the invoice and due timestamp remain
available to the collection sweep. No gateway request or activation occurs
inside the subscription mutation lock. Legacy off-anchor periods can bridge to
the next day10 boundary; paid historical dates and amounts are not rewritten.

## Quote preservation and upgrade rollback

The preview now returns a **stored `quote_id`**. Public plan confirmation
requires it. The snapshot binds subscription, source/target plan, period,
effective immediate/scheduled mode, upgrade classification, proportional
amounts, tax, discount, coupon context and currency. All are captured from one
plan snapshot before the pricing call. A later catalogue or tax/coupon change
does not silently alter a valid quoted adjustment. A quote cannot be used on
another subscription, a different period/target/mode or twice to create charges.

Internal service callers may explicitly request fresh pricing without a prior
quote. Degraded previews remain provisional; only those degraded invoices
follow automatic repricing. The fixed terms of a successful non-degraded quote
are never repriced after issuance.

`initial` and `renewal` invoices retain one regular invoice per subscription
period. `adjustment` invoices reference a unique plan-change audit row and can
coexist with regular invoices and other **completed** adjustments in that
period. A new change is blocked while an earlier adjustment remains pending.
The immediate upgrade is provisional until the adjustment is paid.

Adjustment due time is immediate. Its deadline uses the existing checkout
validity (seven days by default), capped at the end of the quoted service period.
A checkout created later cannot extend that deadline. Retryable refusals keep
the upgraded plan and follow the existing retry schedule. An exhausted or
non-retryable refusal reverts once; an unpaid deadline voids the invoice and
reverts once even if no callback ever arrives. An outage or unverified callback
is not interpreted as a card refusal.

Reversion restores the prior plan and any scheduled downgrade that the upgrade
had displaced, preserves subscription status/access, and adds a zero-amount
`reversion` audit row. Existing failed charge audit rows remain. Settlement and
reversion serialize on the same invoice lock and commit their related effects
atomically. Once reverted, an old link cannot reapply the upgrade: a later
verified payment requires reconciliation, retaining the financial evidence.
Refunds, credit notes and partial-payment accounting remain excluded.

## Automatic pricing recovery

Every minute `invoices:reprice-degraded` queues eligible open degraded invoices.
Each job acquires the shared invoice lock, rechecks eligibility and requests
current tax/coupon rules using the **stored contracted subtotal** and stored
region/coupon context. Legacy records without a stored region use the configured
region. A changed catalogue base price does not rewrite a previously invoiced
service amount.

A continued outage leaves the previous amounts and collection block intact,
with persisted exponential backoff from60seconds to one hour. Valid recovery
atomically records new discount/tax/total, `repriced_at`, attempt count and the
next collection timestamp. Invoice intents, charge attempts, non-degraded,
settled and terminal records are never repriced. Expired adjustments revert
instead. Collection dispatch follows the recovery commit; the due sweep can
recover a missed dispatch.

Hourly renewals catch day10 boundaries and periods released after a provisional
upgrade expires. Collection, pricing recovery and adjustment expiry sweep every
minute. The scheduler and billing queue worker must remain running.

## Operational compatibility and evidence

Apply migrations before starting updated workers. The old
`BILLING_DUE_IN_DAYS` setting no longer determines regular due dates: the day10
calendar does, including the first day10 on/after a legacy off-anchor period start. Rolling back the financial migration is refused while
pending-payment or adjustment records exist; reconcile them rather than dropping
financial history.

The OpenAPI contract adds quote confirmation, invoice kind/deadline, pending
payment status and reversion metadata. Monitoring includes the new subscription
status without per-customer labels. No real payment account was activated or
financial request executed during implementation.

Evidence: `FinancialPolicyTest`, existing subscription/API/billing regressions,
`tests/Feature/Api/OpenApiContractTest.php`, and the
[execution record](../tasks/2026-11-22-financial-policies.json).
