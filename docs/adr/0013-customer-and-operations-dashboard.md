# ADR-0013: Customer and read-only operations dashboard

- Status: accepted; backend implemented, React UI and public deployment pending
- Date: 2026-11-23 (America/Sao_Paulo)
- Partially supersedes ADR-0006's bearer-only assumption for the future browser dashboard

## Context

The owner prioritized the dashboard before AI remediation and selected the
following scope interactively. Wireframes were presented for review before
writing this specification. They are low-fidelity proposals using fictional
data; acknowledging the wireframes does not approve production visual details
or imply a working frontend, authenticated demo or deployment.

## Accepted decisions

1. Separate customer and internal-team areas. Customers can manage subscriptions,
   plans, invoices and payments. The internal area is read-only: search customers
   and inspect subscriptions, invoices and outstanding charges.
2. Provide Portuguese and English with a language selector.
3. Use a sober financial SaaS appearance, initially light, with clear typography
   and restrained accent color. Use shadcn/ui and Tailwind with project-specific
   styling; final colors, typography and component treatments remain to be refined.
4. Build a React/strict TypeScript SPA with Vite, React Router, TanStack Query
   and an OpenAPI-generated typed client. Laravel remains the business-rule owner.
5. Serve browser UI and API from the same origin. Authenticate the dashboard with
   an HttpOnly session cookie and CSRF protection. Preserve bearer-token access
   for existing integrations; deliberately adapt authorization for cookie sessions.
6. Support local Docker execution and plan an isolated public demo with synthetic
   data and simulated payments. Hosting/deployment configuration remains pending.
7. Isolate each demo visitor's data and allow that visitor to reset their demo.
   Demo entry offers “Explore as customer” and “Explore as internal team” without
   typing credentials. This is a demo-only provisioning path, not normal login.
8. Allow free exploration with prepared active-subscription, pending-payment and
   plan-change scenarios rather than requiring a guided tour.
9. Customer navigation: Home, My subscription, Plans and Invoices. Home prioritizes
   current subscription, next charge and the most relevant pending action.
10. Internal navigation: Home, Customers and Billing. Home prioritizes customer
    search and shortcuts to outstanding charges. Billing is inquiry, not a manual
    collection or payment-management console.
11. Plan change has a dedicated review page showing current/target plan, amount
    charged now, future recurring amount and effective date before confirmation.
12. Default cancellation to the end of the period; expose immediate cancellation
    explicitly and review the access-loss date. Existing financial eligibility
    rules, including immediate cancellation of an unpaid initial subscription,
    remain authoritative.
13. The demo illustrates successful, declined and pending-confirmation payments,
    always labeled as simulations. No real financial request is made.

## Authentication consequences

ADR-0006 anticipated revisiting its deployment assumption when a first-party SPA
arrived. Stateful middleware, session login/logout, CSRF and effective browser
capabilities are now implemented. Trusted browser domains remain explicit
deployment configuration. The current-user response supports both browser
sessions and scoped integration tokens.

Sanctum SPA authentication does not preserve personal-token scope semantics
automatically. Enforce the read-only internal-session ceiling on the backend,
including direct requests, while preserving customer ownership checks and the
existing narrower-token restrictions for integrations. Hiding buttons is not
authorization. Effective browser capabilities and middleware now enforce the
read-only session ceiling independently of the broader staff integration role.

## API and demonstration consequences

Existing endpoints cover many customer actions, but the SPA needs discovery of
the authenticated customer's subscriptions and additional read-only internal
list/search operations. Demo isolation must also bound staff access to the
visitor's sandbox, including jobs, caches and resets. Existing staff-wide access
and a global set of shared seeds cannot provide that boundary by themselves.

Do not expose the real gateway or introduce a production authentication bypass
to make the public demo convenient. Numerical limits, fixture lifecycle and
deployment details must be settled before public activation.

The [dashboard specification](../DASHBOARD.md) records screens, flows, permission
requirements, backend dependencies, proposed acceptance checks and remaining
implementation details. ADR-0011's billing rules remain unchanged. The
[backend integration guide](../DASHBOARD_BACKEND.md) documents the delivered
session/read/demo APIs; the OpenAPI contract now contains 27 operations.
