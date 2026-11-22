# Operations

## Local InfinitePay webhooks with Cloudflare Tunnel

The development stack runs locally in Docker. A Cloudflare Quick Tunnel
provides the public HTTPS entry point needed for a payment provider to reach
the local webhook. This is the same workflow used for the local JBoot APIs;
the port and callback path belong to each application, rather than the shared
merchant account.

| Address | Role |
| --- | --- |
| `http://localhost:8000` | Default host address for this Docker API |
| `http://app:8000` | API address for services inside the Compose network |
| `https://your-tunnel.trycloudflare.com` | Example public address; replace with the actual tunnel hostname |
| `/api/v1/webhooks/infinitepay` | This application's POST notification endpoint |

The Compose mapping is `${APP_PORT:-8000}:8000`: only the published host port
changes when `APP_PORT` changes. Commands below run on the Docker host with
`APP_PORT=8000`. If that port is already occupied, choose a free host port and
update the local health check and tunnel origin accordingly.

### 1. Start the local stack

Install Docker Compose and [cloudflared](https://developers.cloudflare.com/tunnel/downloads/).
Follow the repository's initial setup and keep the local `.env` untracked.
Use synthetic data and disable debug output while exposing the application:

```dotenv
APP_PORT=8000
APP_URL=http://localhost:8000
APP_DEBUG=false
BILLING_GATEWAY=fake
```

From the repository root:

```bash
docker compose up -d --build
docker compose ps
# Run after the app is healthy:
curl --fail http://localhost:8000/up
```

Keep `app`, `worker` and `scheduler` running; payment verification uses the
billing queue and its scheduled recovery sweep. The remaining Compose services
provide the database, Redis, pricing and risk processing. Keeping the gateway
as `fake` permits local development without creating real checkout links.

### 2. Start the public tunnel

Run on the same host, outside the application container, in another terminal:

```bash
cloudflared tunnel --url http://localhost:8000
```

Keep that process running and copy the HTTPS hostname it prints. Quick Tunnels
provide a temporary address without requiring your own domain or Cloudflare
account. The hostname changes with a new tunnel and stops working when its
process stops. They are intended for development, not stable production
callbacks. See [Cloudflare's Quick Tunnel documentation](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/).

The tunnel exposes the application's HTTP port. Private routes still require
their normal bearer credentials and ownership checks. Avoid an interactive
email challenge on the webhook path: the provider cannot complete browser
login. The webhook accepts bounded identifiers and verifies payment separately.

### 3. Configure the webhook and reload Laravel

Replace the example hostname below with the actual generated hostname in the
local `.env`:

```dotenv
INFINITEPAY_WEBHOOK_URL=https://your-tunnel.trycloudflare.com/api/v1/webhooks/infinitepay
```

For deliberate live checkout testing, configure `INFINITEPAY_HANDLE` and
`INFINITEPAY_API_KEY` locally, then select `BILLING_GATEWAY=infinitepay`.
The key enables this adapter's single-payment and expiry options. Never commit
the key, environment file or tunnel credentials. `INFINITEPAY_REDIRECT_URL`
is optional; leave it empty unless a browser-return endpoint actually exists.
It is independent of the server notification URL.

```bash
docker compose exec app php artisan config:clear
docker compose restart app worker scheduler
```

The restart reloads the environment in long-running workers and the scheduler.
Wait for the app to become healthy again. `APP_URL` remains the local base URL;
`INFINITEPAY_WEBHOOK_URL` explicitly supplies the public callback address.
Creating a tunnel does not configure the environment automatically, activate
the payment driver or create a payment.

### 4. Verify delivery without creating a payment

Replace the hostname and submit a deliberately unknown order:

```bash
curl --include --request POST \
  https://your-tunnel.trycloudflare.com/api/v1/webhooks/infinitepay \
  --header 'Content-Type: application/json' \
  --data '{"order_nsu":"documentation-probe","transaction_nsu":"documentation-probe","invoice_slug":"documentation-probe"}'
```

Expected response: HTTP **202** with `{"status":"accepted"}`. Unknown orders
are acknowledged and ignored; this checks public routing and request handling,
not payment confirmation, account credentials or end-to-end settlement.
The `/up` check and this unknown-order probe create no payment links or charges.

There is [no InfinitePay sandbox](https://www.infinitepay.io/checkout-documentacao).
The automated suite uses HTTP fakes. A real link/payment is a separate live
financial operation, not a necessary step to verify tunnel routing.

### Restarting or stopping the tunnel

Each checkout stores the webhook URL used when its link was created. After a
tunnel address changes, update the environment and reload the processes for
**new** checkouts. Existing links continue to notify their old address. Do not
delete their intents or recreate links automatically: that risks duplicate
payment opportunities. Keep the original tunnel alive while exercising its
links; reconcile missed notifications through the existing authenticated
payment-check flow when the transaction and slug are available.

Stop the tunnel with Ctrl+C after the demonstration. This closes its public
entry point but does not cancel provider checkout links. A stable production
webhook requires a persistent public address and appropriate deployment.

## Operational metrics

`GET /api/v1/metrics` provides a global, read-only snapshot of the Laravel
billing database. It uses [Prometheus text exposition 0.0.4](https://prometheus.io/docs/instrumenting/exposition_formats/),
with bounded status labels and a `text/plain; version=0.0.4; charset=utf-8`
response. It does not call the pricing service, InfinitePay, or the risk worker.

### Access and scraping

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

### Metric contract

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

## Scheduled billing work

The financial scheduler also runs `invoices:reprice-degraded` and
`invoices:expire-adjustments` every minute, with hourly subscription renewal.
See ADR-0011 for the approved day10 and first-payment rules.
