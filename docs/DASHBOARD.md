# Customer and operations dashboard

Status: decisions accepted on **2026-11-23**; low-fidelity wireframes reviewed;
implementation pending. This specification consolidates the owner's selections.
[ADR-0013](adr/0013-customer-and-operations-dashboard.md) records the architecture
and the planned change to browser authentication. The dashboard is prioritized
before the separately approved [AI remediation design](AI_REMEDIATION.md).

No frontend application, new endpoint, session authentication, public demo or
deployment is delivered by this documentation. The conversational wireframes
illustrate layout and local state transitions; their prices, plan features,
invoice identifiers and customer records are fictional, not a product catalogue.

## Users and scope

| User | First-version scope | Boundary |
| --- | --- | --- |
| Customer | Manage their subscription, compare plans, preview/confirm changes, cancel, inspect invoices and pay | Only their own customer account; server policies and financial eligibility apply |
| Internal operator | Search customers and consult subscriptions, invoices and outstanding charges | Read-only in the dashboard; no changes, cancellations, manual charges or catalogue administration |
| Public demo visitor | Explore either profile, switch among prepared scenarios and reset their data | Synthetic data and simulated payments, isolated from other visitors and real accounts |

Registration, password reset, email verification, refunds, partial payments and
automatic recurring card debit remain outside the existing product scope.
The dashboard does not add customer/plan administration or accounting KPIs.
Operational gauges are not a substitute for financial reporting definitions.

## Approved experience and navigation

Use Portuguese and English with a visible selector. The first visual direction
is sober financial SaaS, light theme, legible typography and a restrained accent.
Build the UI with shadcn/ui, Tailwind and reusable project components. Exact
branding and final visual treatment remain open; dark mode was not selected.

| Area / screen | Main content and actions |
| --- | --- |
| Demo entry | “Explore as customer” / “Explore as internal team”, clearly marked as simulation; normal login remains separate |
| Customer / Home | Subscription state, service access, next recurring charge/date and priority action such as paying an initial invoice; recent invoices |
| Customer / My subscription | Current and scheduled plan, period/trial, access, cancellation state and change history; compare plans or request cancellation when eligible |
| Customer / Plans | Active plans, recurring amount/currency/cadence, trial and available features; select a plan and request an API preview |
| Customer / Plan-change review | Current/new plan, quoted amount payable now, tax/discount, future recurring amount, effective date and explicit confirmation/back actions |
| Customer / Invoices | Paginated invoices and their amounts, dates and statuses; link to detail |
| Customer / Invoice detail | Amount breakdown, period, deadline, payment and checkout state; eligible payment action and asynchronous confirmation status |
| Customer / Cancellation review | Period-end default, explicit immediate option and access-loss date; retain subscription or confirm cancellation |
| Internal / Home | Customer search plus shortcuts to outstanding invoices |
| Internal / Customers | Search results and customer detail, including associated subscriptions and invoices |
| Internal / Billing | General read-only invoice inquiry and status filtering, with customer context and invoice detail |

The main menus are **Home / My subscription / Plans / Invoices** for customers
and **Home / Customers / Billing** for the internal area. Detail and review
screens belong to those sections rather than adding top-level menu entries.
Exact routes, search fields, filter options and pagination defaults remain API/UX
contract work; examples in wireframes do not freeze those choices.

## Customer journeys

### Login and entry

Normal access authenticates existing accounts, resolves effective capabilities
and loads the correct area. A customer must discover their subscription through
an authorized API response; no manual UUID entry or browser-only remembered UUID
is acceptable. Handle an account with no subscription, an unpaid initial
subscription and historical subscriptions deliberately.

Public demo entry provisions/selects an identity inside that visitor's sandbox.
Changing the demo profile must establish the corresponding server-enforced
permissions. It must not turn a real customer into staff or expose global staff
data. Demo provisioning is not a replacement for normal login.

### Contract or change a plan

For a customer with no subscription, select an available plan, review the terms
and create the subscription. Honor trial eligibility and initial payment gating
from the API; the prototype's active scenario does not replace this empty state.

For changes, request a preview and review the dedicated page before confirming
with its stored `quote_id`. The API owns amounts, tax, coupon, eligibility and
immediate/scheduled mode. Do not duplicate proration arithmetic in JavaScript.
Invalidate stale previews when their inputs change and handle a quote rejected
because the subscription changed. Display API-confirmed outcomes before updating
financial/access state; disabling a button is not duplicate-request protection.

Upgrades may create an unpaid adjustment; show that amount separately from the
next recurring charge. Scheduled downgrades leave the current plan in place and
change the future plan/amount at the approved date. An unresolved adjustment can
restrict further changes. Existing ADR-0011 policies govern reversals and trials.

### Payment

Read invoice/checkout eligibility from the backend and present the amount and
currency before the payment action. Normal InfinitePay use follows its hosted
checkout and verified settlement contract. The public demo uses simulated
outcomes only, with no live checkout or provider credentials.

Show successful, declined and pending-confirmation states. A redirect, callback
or 202 response alone cannot mark an invoice paid. Pending initial payment keeps
access blocked; a pending upgrade adjustment must not incorrectly claim that
existing access is blocked. Paid initial and adjustment invoices have independent
states, and one customer's payment state must never appear on another's invoice.

After returning from checkout, re-query authoritative state. Confirmation
refresh/polling needs bounded retries and a manual retry path; its exact interval
and timeout remain implementation configuration. Do not automatically retry
financial mutations after an ambiguous network failure without reconciliation.

### Cancellation

Default to the paid period's end and offer immediate cancellation explicitly.
Show the exact resulting loss-of-access date before confirmation. Do not promise
refunds or invent a retention policy. An unpaid initial subscription cancels
immediately under the existing rule. Show the server-confirmed scheduled or
immediate outcome, retaining historical invoice access where authorized.

## Frontend architecture

Approved stack: React, strict TypeScript, Vite, React Router, TanStack Query,
OpenAPI-generated typed client, shadcn/ui and Tailwind. Use an SPA consuming the
Laravel API, with browser UI and API presented through the same origin.

Suggested implementation boundaries, not fixed directory names:

- App shell, routing, session identity and language provider.
- Customer subscription, plan/quote and invoice/payment features.
- Internal customer and invoice lookup features.
- Demo entry, profile/scenario controls and reset.
- Shared design tokens, reusable controls, API client and error normalization.

Keep remote state in the query layer and transient UI state local. Scope query
keys to the authenticated identity/demo generation; clear protected caches on
logout, profile/session changes and demo reset. Generate transport types from
the contract rather than duplicating them. The generator, i18n library, form
library, test tools, exact dependency versions and source directory are not yet
selected. Extend the OpenAPI contract when implementing the new API operations.

## Session and permissions

Use an HttpOnly session cookie with CSRF protection for browser authentication.
Configure Secure cookies for HTTPS deployment, deliberate SameSite/path/domain
settings, session rotation on login/profile changes, logout invalidation, expiry
and rate limiting. Never expose session credentials to JavaScript or store them
in localStorage. A public demo session is a real authorization boundary even
when its data is synthetic.

Preserve bearer tokens for existing API integrations and their narrow scopes.
Do not assume Sanctum's session `tokenCan()` behavior enforces personal-token
abilities. The backend must calculate and enforce effective dashboard
capabilities: internal sessions are read-only, customer sessions retain
ownership and authorized self-service actions. Return enough identity and
capability information for navigation without treating hidden UI as protection.

The existing `staff` role and policies can act across customers, and its default
token abilities include writes. Implement the approved read-only browser-session
boundary explicitly; choosing a role/capability mapping is implementation work,
not a claim that current staff authorization already satisfies this design.
Update the current-user resource so cookie sessions do not depend on a persisted
personal access token's fields.

## Backend dependencies

| Need | Current position | Required implementation |
| --- | --- | --- |
| Normal browser login/logout and identity | API token issuance/revocation exists; SPA session path is deliberately not enabled | Stateful session/CSRF lifecycle and session-compatible identity/capabilities |
| Discover customer subscriptions | Read-by-UUID exists; identity does not discover subscriptions | Authorized current/history lookup and no-subscription outcome |
| Internal customer inquiry | No customer list/search API | Bounded search, pagination, customer detail and associated subscriptions |
| Internal general invoice inquiry | Invoice detail and per-subscription list exist | Customer-aware general list/filter endpoint with read-only authorization |
| Upcoming charge and scheduled changes | Subscription data exists but not a complete dashboard summary | Confirm contract for authoritative next amount/date, scheduled plan and action eligibility |
| Public demo | Shared local seeds and fake gateway exist | Per-visitor provisioning/isolation, prepared scenarios, safe reset/expiry and simulated payment transitions |

These are required capabilities, not approved new endpoint names. Review the
read/write authorization matrix and update OpenAPI before frontend integration.
The existing contract still has 16 operations; this documentation adds none.

## Isolated demo lifecycle

Support active-subscription, pending-payment and plan-change scenarios with free
exploration. Let a visitor reset their own demonstration. The operator can
inspect multiple synthetic customers within that sandbox, never other visitors.

Isolation must apply on the server to queries, policies, async jobs, queue
payloads, caches, locks and any aggregate data exposed. Scope or replace broad
staff access within the demo context. A reset must not let queued jobs or late
events from the old scenario mutate a newly initialized one. Choose a generation
or equivalent lifecycle mechanism during implementation.

Use a dedicated demo environment with no real customers, production credentials
or live payment routes. Payment simulation controls must not be enabled merely
by a browser parameter or available in the normal payment environment. The
existing amount-driven fake gateway is not proof that all interactive demo
outcomes, isolation or reset behavior already exist.

Session TTL, cleanup, resource/concurrency limits, abuse protection, hosting,
domain and costs must be defined before public activation. Public deployment is
a separate action; approving a public-demo design does not deploy it.

## Proposed implementation acceptance checks

The following engineering checks translate the accepted scope into reviewable
delivery requirements; they are not claims of executed tests or final visual
approval:

| ID | Evidence required before calling the dashboard complete |
| --- | --- |
| D1 | Both areas work against the real API, with customer ownership and internal read-only restrictions enforced even for direct requests |
| D2 | Login/logout, CSRF, session expiry/rotation and existing bearer-scope regressions pass; cookie identity/capabilities work without a personal token |
| D3 | Customer discovery, no-subscription/initial-payment states, creation, plan preview/confirmation, cancellation and invoice pagination are covered |
| D4 | Displayed amounts/dates come from authoritative contracts; pending adjustment does not block access incorrectly; downgrade changes future charge; paid invoices stay paid |
| D5 | Two concurrent demo visitors cannot read/change/reset each other's data; internal access, cached results and background jobs respect sandbox/reset boundaries |
| D6 | Simulated approval/decline/pending outcomes are clearly labeled and cannot call a real payment provider |
| D7 | PT/EN navigation, actions, validation, empty/error states and status labels are complete; language affects presentation, not currency or financial rules |
| D8 | Keyboard navigation, focus, labeled forms, non-color status cues, loading/empty/error/unauthorized states and narrow-screen layouts are verified; accessibility target and test coverage are finalized |
| D9 | Generated client matches updated OpenAPI; frontend and affected backend checks run in GitLab CI; Docker starts UI/API together with the intended same-origin setup |
| D10 | Runtime documentation and delivery status distinguish local verification from a hosted deployment, and successful UI mutations from pending financial settlement |

## Remaining definition work

- Final screen content and appearance beyond the reviewed low-fidelity layout.
- Complete API schemas, error/eligibility contracts and browser capability mapping.
- Demo storage/isolation strategy, lifecycle limits and deployment configuration.
- Dependency/tool versions, generator, i18n/forms/testing choices and build layout.
- Accessibility target, responsive breakpoints, session timing and confirmation-refresh limits.

Registration and other exclusions do not become implied backlog items. Financial
rules continue to follow ADR-0011; browser authentication follows ADR-0013 once
implemented. This specification is the handoff for implementation, not its
completion report.
