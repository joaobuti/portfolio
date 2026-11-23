# Delivery status

Reviewed against the repository on 2026-11-22. This is an implementation
inventory, not evidence that the current CI pipeline passed.

Planning update on 2026-11-23: the owner approved the AI remediation architecture
in ADR-0012. Only its documentation is delivered; runtime implementation and
continuous monitoring remain pending.

Dashboard planning update on 2026-11-23: the owner prioritized the dashboard,
selected product/technical decisions and reviewed low-fidelity wireframes.
ADR-0013 and DASHBOARD.md consolidate those decisions. Backend sessions, read
APIs and isolated demo APIs are now implemented; frontend and public deployment
remain pending. See [backend integration](DASHBOARD_BACKEND.md).

## Implemented

| Area | Delivered | Evidence |
| --- | --- | --- |
| Subscriptions | Creation, trials, plan changes, cancellation, renewal and change history | `SubscriptionService`; `tests/Feature/Services/SubscriptionServiceTest.php` |
| Authentication | Token issuance/revocation, browser sessions/CSRF, effective capabilities and ownership policies | `routes/api.php`; `tests/Feature/Api/SubscriptionAuthorizationTest.php` |
| Pricing | Tax/coupon quotes, client timeout, circuit breaker and degraded fallback | `services/pricing/src`; `tests/Feature/Services/Pricing/HttpPricingClientTest.php` |
| Renewal billing | Period invoices, charge attempts, dunning and fake gateway | `IssueInvoiceOnRenewal`; `tests/Feature/Services/Billing/BillingServiceTest.php` |
| Risk | Redis event consumption, scoring, retries, dead letters and score return to Laravel | `services/risk-worker`; `tests/Feature/Risk/RiskIntegrationTest.php` |
| InfinitePay | Native Laravel hosted checkout, durable notification inbox, verified settlement and scoped invoice API | ADR-0009; `InfinitePayCheckoutTest`; `InvoiceEndpointsTest` |
| API contract | OpenAPI 3.1 for all current API v1 operations, including required scopes | `docs/openapi.json`; `OpenApiContractTest` |

| Operational metrics | Staff-only Prometheus gauges for billing state and verification backlog, cached read-only snapshots | `OperationalMetrics`; `MetricsEndpointTest`; `docs/OPERATIONS.md` |
| Dashboard backend | Read-only internal browser sessions, scoped paginated lookup, next-charge estimate and isolated synthetic demo APIs | `tests/Feature/Dashboard`; `docs/DASHBOARD_BACKEND.md` |

## Financial policies (approved 2026-11-22)

| Rule | Implemented behavior | Evidence |
| --- | --- | --- |
| Initial invoice without a trial | Immediate whole-day proportional invoice; `pending_payment` denies access until initial settlement; subsequent interval boundaries are day10 America/Sao_Paulo | `BillingCalendar`; `IssueInitialInvoice`; `FinancialPolicyTest` |
| Upgrade adjustment | Stored preview quote preserves tax/coupon and mode; a separate adjustment invoice is issued atomically; exhausted retries or unpaid deadline revert once and restore displaced scheduled downgrade | `PlanChangeQuote`; `IssueAdjustmentInvoice`; `FinancialPolicyTest` |
| Degraded pricing | Automatic current-rule recalculation against contracted subtotal and original region/coupon context, with durable backoff; payment-bound or settled invoices remain immutable | `RepriceDegradedInvoice`; `invoices:reprice-degraded`; `FinancialPolicyTest` |

These three decisions are now resolved. Existing free trials remain a prior
exception; their first paid period requires settlement. See
[ADR-0011](adr/0011-payment-activation-billing-day-and-adjustments.md) for
calendar examples, quote confirmation, deadlines and deployment considerations.

## Planned work

- Customer and read-only operations dashboard (current priority): React/strict
  TypeScript, Vite, React Router, TanStack Query, generated OpenAPI client,
  shadcn/ui and Tailwind; Portuguese/English. Customer journeys cover
  subscriptions, plans, invoices and payments. The internal area searches
  customers and consults subscriptions/invoices. The browser session/CSRF, read
  APIs and isolated demo backend are implemented. Frontend integration and public
  deployment are still pending. The separate local Compose API demo is available. See
  [ADR-0013](adr/0013-customer-and-operations-dashboard.md) and the
  [screen map, dependencies and proposed acceptance checks](DASHBOARD.md).
- AI-assisted incident remediation, approved on 2026-11-23: a separate service
  captures sanitized errors, groups incidents, reproduces code defects and
  prepares tested corrections as GitLab Merge Requests. Human review controls
  merges/deployment, followed by explicit GitHub synchronization. The first
  milestone is a controlled synthetic demonstration, before continuous monitoring.
  See [ADR-0012](adr/0012-ai-assisted-incident-remediation.md) and the
  [workflow, limits and acceptance criteria](AI_REMEDIATION.md). No collector,
  coding agent, publisher or synchronization worker is implemented by this
  documentation delivery; providers, budgets, hosting and credentials remain
  activation/implementation configuration.

The dashboard frontend remains planned. Backend session/capability enforcement,
scoped lookup operations and synthetic demo workspaces are implemented; final
visual design and public hosting remain pending. Operational billing metrics are
implemented; accounting KPIs and Node/Python infrastructure metrics remain
outside that scope.

## Explicit exclusions

Refunds, credit notes and partial payments remain excluded by ADR-0007.
ADR-0009 supersedes its real-gateway exclusion for the approved InfinitePay
hosted checkout flow only. Registration, password reset and email verification are
excluded by ADR-0006. Their absence is not an implementation defect under the
current scope.

## Verification commands

- `make test`: PHP Unit, Arch and Feature suites.
- `make check`: PHP formatting, PHPStan, Rector and the PHP test suites.
- `make domain-check`: standalone PHP money/proration checks.
- `make entrypoint-check`: isolated startup regression check with database
  settings loaded from `.env`; also runs in CI's `unit` job.
- Pricing and risk checks: see their respective service READMEs.
- CI additionally runs coverage, dependency auditing and image builds.

The static analysis and test definitions are evidence of intended checks, not
proof that they have passed. Consult the actual pipeline for a particular
commit. Feature tests default to SQLite and an array cache in `phpunit.xml`;
the CI `feature` job overrides those defaults with Postgres and Redis.

## Technical corrections (2026-11-21)

- Container database readiness now uses Laravel's resolved connection settings,
  including `.env` and cached configuration, instead of raw shell variables.
- Renewal collection jobs use the invoice's persisted `next_attempt_at`, so a
  configured payment term does not result in immediate collection. Their
  infrastructure retry deadline starts from the scheduled availability time.
- Zero-value settlement clears the retry timestamp and applies the existing
  past-due-to-active transition, without calling the gateway or recording a
  fictitious attempt. The `InvoicePaid` event retains its required gateway
  attempt and is still emitted only for gateway-backed payments.
- Plan catalogue cache entries contain scalar attribute arrays, hydrated into
  models on read. This keeps serialized cache stores compatible with disabled
  PHP class deserialization. A versioned key separates the new cache format
  from old serialized model entries.
- PHPStan type errors were corrected at the money cast boundary, the activity
  listener's after-commit flag and filesystem URL configuration. The money
  cast retains its null handling and Money-only write contract, and rejects
  stored column types it cannot safely interpret.

Those technical corrections preceded the financial implementation in ADR-0011.

## Comprehensive technical audit (2026-11-21)

- Gateway attempts, resulting invoice state and subscription status commit in
  one transaction. Collection enforces persisted due/retry timestamps.
- Renewal events snapshot their period and price before entering the queue,
  so later model changes cannot rewrite the period being invoiced.
- Plan changes revalidate the refreshed subscription while holding its lock.
  Scheduled downgrade previews return zero; an explicit immediate downgrade
  previews the existing prorated credit policy.
- Pricing responses must match the requested subtotal and reconcile taxable
  amounts. HTTP status errors are not connection retries. Node rejects unsafe
  computed totals and returns the documented error for oversized requests.
- Payment-failure event identities survive listener retries. The Python worker
  atomically publishes, records completion and acknowledges; its return leg
  retains claimed messages across database failures and rejects invalid UUIDs.
- Local seeds can run repeatedly without duplicate demo customers or resetting
  edited plan prices. Production startup requires an injected key, caches
  runtime configuration, and omits automatic demo seeding. Docker contexts
  exclude host dependencies, environment files and development artifacts.
- Coverage CI uses Postgres and Redis. The full Rector gate is reconciled;
  Eloquent observer callback parameters retain their framework contract.
- Vulnerable Composer dependencies were updated within the existing manifest
  constraints; runtime and toolchain checks use the refreshed lock file.

At that audit, the three financial decisions were still open. They were later
resolved and implemented under ADR-0011; the audit itself introduced no new
financial policy.

## Verification results (2026-11-21)

| Check | Result |
| --- | --- |
| Full PHP suite with PostgreSQL 17 and Redis 7 | 304 tests, 628 assertions passed |
| PHP line coverage | 93.6%, exceeding the existing 80% gate |
| PHPStan maximum level, full Pint and full Rector dry-run | Passed |
| Pricing on Node 22 | 37 tests and TypeScript checks passed |
| Risk worker on Python 3.12 with real Redis | 64 tests, strict mypy and ruff passed |
| Composer and npm dependency audits | No reported vulnerabilities |
| PHP, pricing and risk Docker targets | All development and production builds passed |
| Isolated production roundtrip | PHP pricing call to Node, fake failed payment, Python score, Redis return and PHP persistence passed |
| Startup and standalone domain regressions | Passed; 43 standalone domain checks |

These are local verification results, not a claim that a remote GitLab pipeline
has executed. Temporary services used a dedicated network and test databases;
no deployment or real payment was performed. The execution/review record is in
[tasks/2026-11-21-comprehensive-corrections.json](tasks/2026-11-21-comprehensive-corrections.json).

## InfinitePay activation (2026-11-22)

The adapter and API are implemented; the default remains `fake`. Production
activation requires the real InfiniteTag, a server API key supporting
`single_payment`/`valid_until`, and this project's public HTTPS webhook URL.
The existing JBoot integration stores its merchant handle in its payments
database, rather than configuration templates. No real account values were
recovered or committed. See ADR-0009 and the README for the configuration trace.

The InfinitePay delivery did not implement automatic recurring card debit.
The three subsequent financial decisions are implemented under ADR-0011. No real link creation, payment or deployment
was performed. Verification evidence is recorded in
[tasks/2026-11-22-infinitepay-checkout.json](tasks/2026-11-22-infinitepay-checkout.json).

### InfinitePay verification results

- Full PHP Unit/Arch/Feature suite with PostgreSQL 17 and Redis 7: **350 tests,
  851 assertions passed; 94.0% line coverage** (80% gate).
- PHPStan maximum level, full Pint (188 files), full Rector dry-run and
  OpenAPI 3.1 validation passed. Contract regression covers all 15 API operations.
- Production image built and booted in isolation with cached configuration,
  routes and events; migrations, gateway bindings and scheduled verification
  passed without calling the financial API.
- Independent review approved after correcting preview nullability and the
  zero-invoice response shape. These are local results, not a remote CI run.

## Operational metrics delivery (2026-11-22)

Staff-only `GET /api/v1/metrics` is implemented with nine read-only gauge
families, fixed status labels and a 15-second cached snapshot. The operations
guide documents the scrape contract, token rotation and failure behavior.
No collector, external alerts or financial KPI policies were introduced.
The OpenAPI contract now covers **16 API operations**.

Verification: **361 PHP tests, 904 assertions, 94.1% line coverage**, PHPStan
maximum level, full Pint (191 files), full Rector and OpenAPI validation passed.
The production image built and handled an authenticated bearer-token scrape
with cached configuration/routes/events and isolated migrations. The actual
response passed the Prometheus parser: nine gauges, 23 bounded series and no
duplicates. Independent review found no actionable defects.

See [operations](OPERATIONS.md), [ADR-0010](adr/0010-operational-metrics.md),
and the [execution record](tasks/2026-11-22-operational-metrics.json).

## Financial policy verification (2026-11-22)

The three owner decisions and their clarifications are implemented under
ADR-0011. The full PHP suite passed **384 tests, 1,009 assertions and 93.9%
line coverage** on PostgreSQL17/Redis7. The financial/migration regressions
also passed on SQLite: **22 tests, 102 assertions**. PHPStan max, full Pint
(205 files), full Rector, OpenAPI validation and architecture tests passed.

The production image built and ran isolated migrations with cached config,
routes and events. In-process pricing/payment fakes verified initial access
gating, activation, quote-preserving adjustment issuance/settlement and audit.
Its authenticated metrics response parsed as nine gauges and 24 bounded series,
including the new pending-payment status. No real financial HTTP call,
production migration or deployment was performed. Independent review findings
were corrected and regression-tested; the
[execution record](tasks/2026-11-22-financial-policies.json) contains evidence.

## Dashboard backend delivery (2026-11-23)

The Laravel backend now implements the session/read/demo portion of ADR-0013.
The OpenAPI contract covers **27 operations**. Browser sessions use HttpOnly
cookies and CSRF, staff sessions are read-only, and bearer scope restrictions
remain intact. Customer/subscription/invoice discovery is authorized and
paginated. Workspace generations isolate visitors and resets; automatic
financial/risk work excludes demo data.

Verification: **407 tests, 1,202 assertions and 94.4% line coverage** on
PostgreSQL 17 and Redis 7. Dashboard regressions also passed on SQLite:
**23 tests, 141 assertions**. PHPStan maximum level, Pint and Rector checks,
OpenAPI 3.1 validation and 43 standalone domain checks passed. The entrypoint
regression covers injected demo configuration and refusal of a missing key.

The production PHP image booted as a non-root user on a dedicated local Compose
network/database. Real HTTP checks using two independent cookie jars verified
CSRF rejection/acceptance, session rotation, cross-visitor access refusal,
read-only staff requests, simulated settlement, profile switch, reset, stale
cookie rejection and logout. No live payment or public deployment occurred.

Independent review identified and prompted fixes for demo image startup and
unsafe schema rollback. Rollback is refused while synthetic generations exist;
expired generations must be pruned first. See the
[backend guide](DASHBOARD_BACKEND.md) and
[execution/review record](tasks/2026-11-23-dashboard-backend.json). Frontend
implementation, final styling and public activation remain pending.
