# Operational metrics

`GET /api/v1/metrics` provides a global, read-only snapshot of the Laravel
billing database. It uses [Prometheus text exposition 0.0.4](https://prometheus.io/docs/instrumenting/exposition_formats/),
with bounded status labels and a `text/plain; version=0.0.4; charset=utf-8`
response. It does not call the pricing service, InfinitePay, or the risk worker.

## Access and scraping

A staff account must issue a dedicated bearer token with **only** `metrics:read`.
Use the normal `POST /api/v1/auth/tokens` flow and supply
`abilities: ["metrics:read"]`. Customers cannot obtain that ability; the endpoint
also checks staff capability independently, even if a customer credential was
manually assigned `metrics:read` or `*`. Previously issued staff tokens need
reissuing to gain the new scope.

Store the token in a private monitoring secret file and configure Prometheus:

```yaml
scrape_configs:
  - job_name: portfolio_billing
    scheme: https
    metrics_path: /api/v1/metrics
    scrape_interval: 30s
    scrape_timeout: 10s
    authorization:
      type: Bearer
      credentials_file: /run/secrets/portfolio_billing_metrics_token
    static_configs:
      - targets: [billing.example.test]
```

Replace the example target with the deployed API host and mount the token file
in the collector. This change supplies the endpoint and example only; it does
not deploy a collector or configure an external monitoring account. Existing
token expiry applies: rotate/reissue the credential before it expires, using
the account's normal provisioning process. Do not put a password, token or
InfinitePay API key in a tracked scrape configuration.

Scrapes require HTTPS in a deployment. Unauthorized/expired tokens return 401;
wrong scope or role returns 403; the existing API limiter can return 429. A
collection/cache/database error fails the scrape rather than reporting false
zero values. Production must keep its existing `APP_DEBUG=false` setting.

## Metric contract

All metric names below start with `portfolio_billing_`. Every series is a
**gauge**, representing current rows or timestamps, rather than a cumulative
counter. `status` is the only label, and its allowed values are fixed. No IDs,
emails, merchant handles, slugs, transactions, URLs or amounts become labels.

| Suffix | Labels | Meaning |
| --- | --- | --- |
| `snapshot_timestamp_seconds` | None | Unix seconds when the cached snapshot was collected |
| `invoices` | `status`: open, paid, uncollectible, void | Current persisted invoice counts |
| `subscriptions` | `status`: pending_payment, trialing, active, past_due, canceled, expired | Current persisted subscription counts |
| `checkouts` | `status`: creating, awaiting_payment, creation_unknown, creation_failed, paid, expired | Checkout counts using effective local expiry |
| `checkout_notifications` | `status`: pending, confirmed, reconciliation | Stored notification counts by verification state |
| `degraded_open_invoices` | None | Open invoices blocked by degraded pricing |
| `collection_due_invoices` | None | Open, non-degraded invoices with non-null `next_attempt_at` at or before collection time |
| `checkout_verifications_due` | None | Pending signals with non-null `next_verification_at` at or before collection time |
| `oldest_pending_checkout_notification_age_seconds` | None | Age since the earliest pending signal was created; zero if absent or future-dated |

All known status series are emitted even when zero. An awaiting checkout with
`expires_at <= collection time` counts as expired, matching invoice responses;
reading metrics never persists that status. Pending age includes signals waiting
for backoff, not only those due now. Terminal signals do not contribute to age.
The due-collection gauge deliberately follows the persisted collection sweep
eligibility, including its non-null retry timestamp requirement.

The rendered snapshot is cached internally for **15 seconds**. HTTP responses
are `private, no-store`; authorization runs before access to the snapshot.
Metrics use grouped/count/min SQL aggregates, not per-row model hydration.
Queries may observe changes between aggregates: these are operational gauges,
not an atomic financial report. On large databases, measure query/scrape time
before increasing scrape frequency; the cache limits repeated aggregation.
The cache timestamp lets monitoring detect snapshots that are old.

Example expressions to inspect without automatically changing billing state:

```promql
portfolio_billing_checkout_notifications{status="reconciliation"} > 0
portfolio_billing_checkouts{status="creation_unknown"} > 0
portfolio_billing_degraded_open_invoices > 0
portfolio_billing_oldest_pending_checkout_notification_age_seconds
```

Thresholds and alert routing remain operator decisions. These gauges do not
measure MRR, revenue, refunds, partial payments, HTTP latency or infrastructure
health across the Node/Python services. They introduce no financial policies.
See [OpenAPI](openapi.json) for auth and response details, and
[ADR-0010](adr/0010-operational-metrics.md) for the implementation decision.

The financial scheduler also runs `invoices:reprice-degraded` and
`invoices:expire-adjustments` every minute, with hourly subscription renewal.
See ADR-0011 for the approved day10 and first-payment rules.
