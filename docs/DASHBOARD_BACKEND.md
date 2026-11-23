# Dashboard backend integration

This guide covers the Laravel backend for ADR-0013. It does not deliver the
React application or publish a demo. Use the [OpenAPI contract](openapi.json)
for generated client types and the [dashboard specification](DASHBOARD.md)
for the accepted experience.

## Browser session lifecycle

Serve UI and API through the same origin. Populate `SANCTUM_STATEFUL_DOMAINS`
with that host (and port locally), without a scheme. The example environment
allows `localhost:8000` and `127.0.0.1:8000`; configure the real HTTPS host
explicitly. Set `SESSION_SECURE_COOKIE=true` behind HTTPS. The session cookie
is HttpOnly; the separate readable `XSRF-TOKEN` cookie is a forgery-prevention
token, not the authentication credential.

1. `GET /api/v1/auth/csrf` initializes the anonymous session and CSRF cookie.
   The existing `/sanctum/csrf-cookie` route also remains available.
2. `POST /api/v1/auth/session` with `email` and `password` signs in an existing
   account. Send cookies and the URL-decoded `XSRF-TOKEN` cookie value as
   `X-XSRF-TOKEN`. No personal access token is issued.
3. Read `GET /api/v1/auth/me` for the identity, role, customer and effective
   `abilities`. Session identifiers and CSRF tokens rotate on login/profile
   changes: read the current CSRF cookie for each mutation.
4. `DELETE /api/v1/auth/session` invalidates the session and rotates CSRF.
   It is idempotent. Discard private frontend query caches on logout.

Session duration uses the existing `SESSION_LIFETIME`; CSRF failure returns 419,
expired authentication returns 401, and a capability/ownership refusal returns
403. Re-authenticate rather than replaying an ambiguous financial mutation.
Authentication for existing integrations remains bearer-token based. Token
issuance is refused from an authenticated browser session; the browser uses
session logout rather than `DELETE /auth/tokens/current`.

## Capabilities and read operations

Browser customer sessions receive their existing self-service capabilities;
ownership is still enforced by policies. Internal browser sessions receive
`plans:read`, `subscriptions:read`, `invoices:read` and `customers:read`.
The backend denies their unsafe API methods, including quote previews that
persist data. They cannot mint broader tokens or access global metrics.
Their own demo profile/reset controls are separately scoped demo operations.

Existing integration tokens remain bounded by their granted abilities and role
ceiling. A staff integration token may retain existing write capabilities;
this does not widen an internal browser session.

| Method / path under `/api/v1` | Use |
| --- | --- |
| `GET /subscriptions` | Paginated authorized current/history discovery; an empty list is a valid no-subscription state |
| `GET /customers?q=...&page=...` | Internal customer search by name/email, requiring `customers:read` |
| `GET /customers/{customer}` | Internal authorized customer detail |
| `GET /customers/{customer}/subscriptions` | Internal customer subscriptions, requiring both customer/subscription read capabilities |
| `GET /invoices?status=...&customer=...&page=...` | Paginated authorized invoices with customer context; customer users cannot broaden their scope through the filter |

Lists use 20 records per page. Customer query length is bounded to 120
characters; the page range is 1–10000 for the new read endpoints. UUIDs remain
public identifiers. Existing per-subscription invoice and detail routes remain.

Subscription responses add nullable `next_charge`: `at`, target `plan`,
`subtotal` as Money, and `estimated: true`. This is a recurring base subtotal,
not a promise of future tax/coupon totals. A scheduled downgrade changes that
future plan/subtotal; scheduled cancellation or non-renewable state returns
null. Financial confirmation still uses the stored preview quote. New clients
must not derive exact financial totals from the displayed estimate.

## Local isolated demonstration

The demo API is off by default. It requires **all** of:

- `DEMO_ENABLED=true`, `APP_ENV=demo` and `APP_DEBUG=false`.
- A dedicated database with no ordinary users/customers.
- `BILLING_GATEWAY=fake`, `RISK_WORKER_ENABLED=false` and no InfinitePay
  handle/API key. Live checkout routes, webhooks and bearer authentication are
  blocked in the demo environment; the live gateway binding is unavailable.

The separate Compose file creates an isolated PostgreSQL database and Redis,
builds the production PHP target, and starts the API and scheduler. It does not
bind-mount the checkout or its `.env`, run the Python worker, connect to the
normal application database or expose database/Redis ports. The API binds only
to local `127.0.0.1`, at port 8001 by default. These settings are for local
evaluation; public HTTPS hosting, credentials and activation remain separate.

```bash
export DEMO_APP_KEY="$(docker run --rm --entrypoint php php:8.3-cli-alpine -r 'echo "base64:".base64_encode(random_bytes(32));')"
docker compose -p portfolio-billing-demo -f docker-compose.demo.yml up -d --build --wait
curl --fail http://localhost:8001/up
```

Use a client that keeps cookies, first requesting `/api/v1/auth/csrf`, then:

```http
POST /api/v1/demo/session
Content-Type: application/json
X-XSRF-TOKEN: <current URL-decoded CSRF cookie>

{"profile":"customer","scenario":"pending"}
```

`profile` is `customer` or `staff`; `scenario` is `active`, `pending` or
`change`. The latter starts with an active subscription ready for plan-change
exploration, not a pre-applied upgrade. The response contains the ordinary
`data` user resource and top-level `demo` metadata with the workspace UUID,
scenario, expiry and `simulated: true`. Random account passwords are never
returned and cannot be used to mint tokens.

`POST /api/v1/demo/profile` with `{"profile":"staff"}` switches within the
same live workspace. Repeating `POST /demo/session` resets/changes the scenario
by creating a new generation and expiring the previous one. There is no
caller-supplied workspace identifier. Other visitors keep their own data.

## Simulated payments

As the demo customer, call `POST /api/v1/demo/invoices/{invoice}/payment` with
`{"outcome":"pending"}`, `"approved"` or `"declined"`. The invoice must be
owned, open, due and priced. Internal sessions cannot use this operation.

- Pending records `simulation.payment_status=pending` without an attempt or
  settlement. The initial subscription remains blocked.
- Approval uses the normal settlement path with an in-process simulation
  gateway and records an attempt. Repeating it does not charge a paid invoice.
- Decline uses the existing dunning rules and persisted retry date. Immediate
  retries can therefore be rejected as not due. Reset the demo to immediately
  replay a different outcome rather than bypassing those financial rules.

The demonstration uses explicitly fictional zero-tax/zero-discount pricing,
without an external pricing request. Normal deployments keep the Node pricing
integration and its degraded fallback. No financial provider is contacted.
Initial and adjustment invoice states stay independent; invoice responses
include a `simulation` object only when a simulated payment state exists.

## Isolation, reset and cleanup

Users/customers belong to a server-side workspace generation. Policies and read
queries bind data access to that workspace; broad staff access cannot escape
it. Customer ownership remains a separate restriction within the sandbox.
Public plans are shared immutable catalogue data; mutations and entity locks use
globally unique identifiers. No private customer query results are globally
cached. The frontend must still scope/clear its own caches when identity or
generation changes.

Automatic renewal/collection/repricing/expiry jobs and risk transport exclude
demo customers, including jobs queued before reset. Demo financial state moves
only through the explicit user flow. A reset uses new customer/subscription/
invoice identifiers and expires the old workspace, so a late action cannot
modify the new generation. Queued demo lifecycle work is not scheduled.

Workspaces have a configurable absolute lifetime (default 60 minutes) and a
hard stored-workspace cap (default 100, including expired rows until pruned).
Provisioning is serialized with a cache lock and rate limited to five requests
per minute/IP. At capacity, provisioning returns 429 without retiring the
current workspace. The scheduler runs `demo:prune` every five minutes, removing
up to 100 expired generations in dependency order. It only deletes synthetic
workspace data, never ordinary financial records.

The local Compose Redis uses `noeviction`: memory exhaustion fails instead of
silently evicting active locks/sessions. Workspace limits bound fixture growth;
review limits, cleanup, persistent resources and abuse controls before public
hosting. Stopping with `docker compose ... stop` keeps data. Adding `down -v`
to this dedicated Compose project deletes its demonstration database volume.

## Delivery and verification

The feature tests cover session rotation/logout/CSRF, session-versus-token
permissions, pagination/ownership, demo configuration, cross-visitor direct
access, reset/expiry/pruning, simulated payment outcomes and automatic-job
exclusion. The existing financial/static/architecture checks remain required.
Consult the execution record and delivery status for results; this guide is
an integration contract, not evidence of a public deployment.
