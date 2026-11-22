# ADR-0010: Read-only operational metrics

- Status: implemented under the owner's instruction to continue the backlog
- Date: 2026-11-22

The next independent roadmap item is metrics. Add a Laravel scrape endpoint for
current operational state, leaving the three pending financial decisions and
secure InfinitePay activation unchanged. This is the first metrics scope;
accounting KPIs and infrastructure metrics for Node/Python are not included.

Use nine current-state gauge families in Prometheus text format with only
fixed status labels. Aggregate in SQL rather than loading models or emitting
per-customer labels. Derive local checkout expiry without writing status rows;
reuse the existing collection eligibility scope for due invoices. The pending
signal age is based on creation time, clamped at zero. All known states emit
zero when empty, so monitoring does not lose a series when a backlog clears.

A 15-second scalar snapshot cache amortizes reads across scrapes. Include the
collection timestamp so cache age is observable. The aggregates are not an
atomic financial report. Do not report infrastructure failures as healthy zero
values, and do not retain an old snapshot indefinitely on a collection error.

Monitoring requires a staff bearer token with `metrics:read`. Both role and
scope are checked before accessing cached data. Customers cannot gain the
scope through normal issuance and cannot bypass the role restriction with a
manually granted scope. The endpoint uses existing API throttling and sends
private/no-store response headers. No extra runtime package, migration, public
unauthenticated endpoint, external service or real payment action is needed.

The [operations guide](../OPERATIONS.md) defines every gauge and provides a
scrape example, without choosing alert thresholds or sending external alerts.
The [OpenAPI contract](../openapi.json) includes the endpoint and new scope.
Authentication, empty data, current-state aggregates, expiry, backlog age, cache
expiry, outage behavior and scope ceilings are verified by
`tests/Feature/Api/MetricsEndpointTest.php` plus the full project gates.

References: [Prometheus format](https://prometheus.io/docs/instrumenting/exposition_formats/)
and [metric naming](https://prometheus.io/docs/practices/naming/).
