# ADR-0006: Sanctum tokens, abilities, and ownership as three separate checks

**Status** Accepted
**Date** 2026-09-01

## Context

Until now this module had no authentication at all. Every route was reachable
by anyone, and `GET /api/v1/subscriptions/{uuid}` handed any subscription to
any caller who knew — or guessed — a uuid. `config/auth.php` referenced an
`App\Models\User` that did not exist, and every FormRequest returned `true`
from `authorize()`.

That was a deliberate placeholder, and the previous ADRs said so. It is no
longer acceptable in a module that holds what customers pay.

The consumers are a planned React dashboard, the services in the roadmap, and
back-office tooling. All of them are separate origins talking to an API. None
of them is a first-party SPA sharing a cookie domain.

## Decision

**Sanctum personal access tokens, with authorisation split into three
independent checks.**

### 1. A user is not a customer

`users` is its own table with a nullable `customer_id`. A `Customer` is a
billing entity — who is charged. A `User` is an account that authenticates.
Most of the time one belongs to the other, but a staff user belongs to none and
a customer with two people managing the account has two users. Collapsing them
is the shortcut that becomes a migration eighteen months in.

### 2. Three checks, three places

| Question | Answered by | Lives in |
| --- | --- | --- |
| Is this a real caller? | `auth:sanctum` | route middleware |
| Does this credential carry the power? | `abilities:subscriptions:write` | route middleware |
| Is this your data? | `SubscriptionPolicy` | policy |

Folding scope into the policy was the obvious alternative and is worse: every
policy method would then need a token in scope and could not be unit tested
without one. Keeping them apart also produces the behaviour in the tests where
a user who *owns* a subscription is still refused because their token was
issued read-only — which is the correct answer and impossible to express if
one check subsumes the other.

### 3. Ownership has exactly one definition

Every policy method reduces to `User::canActOnCustomerId()`. Not laziness — it
means a role added tomorrow changes `UserRole` and nothing else. A policy that
re-derived "is this mine?" per action is one where three of the four eventually
disagree.

`UserRole` owns the rule (`accessesAnyCustomer()`), the same way
`SubscriptionStatus` owns the transition table. Policies ask it a question;
they never compare role strings.

### 4. Abilities are an enum, and tokens are minted by intersection

Sanctum's abilities are free-form strings, so `tokenCan('subscriptions:wirte')`
fails closed and silently forever. `TokenAbility` names them once.

A token gets **the intersection** of what was requested and what the role
permits — never the union. A customer asking for every ability receives only
theirs. Privilege escalation is impossible by construction rather than by a
check a future endpoint might forget.

### 5. The seam at `TokenIssuer`

`AuthenticationService` verifies credentials; `TokenIssuer` mints tokens. Two
responsibilities with two reasons to change: the first when the password policy
changes, the second when the token scheme does. `SanctumTokenIssuer` is the only
class in the application that imports `Laravel\Sanctum` (besides the trait on
the model), so replacing Sanctum touches one file.

## Security decisions worth naming

**Account enumeration.** "No such email" and "wrong password" return one
identical 401 with one message. They also take the same *time*: the unknown-email
branch hashes against a throwaway bcrypt hash before refusing, because an early
return is measurable and lets an attacker enumerate the customer base without
guessing a password.

**403 rather than 404 for an unknown customer or subscription.** Answering
"that does not exist" lets a caller walk the uuid space and learn which records
are real. The tests pin this.

**Token expiry is set.** Sanctum's default is `null`, meaning a leaked token is
valid forever. `SANCTUM_EXPIRATION=720` ships in `.env.example`.

**Stateful domains are empty.** The published default enables the CSRF-cookie
path for localhost in every environment — a login surface nobody here uses and
nobody would notice being abused.

**Login has its own rate limiter**, keyed on email *and* IP. On the email alone,
one attacker locks a victim out of their own account; on the IP alone, a botnet
spreads a credential-stuffing run across thousands of addresses and never trips
it.

**No `assert()` anywhere.** Pest's security preset forbids it and is right to:
assertions are compiled out in production, so a guard written as one disappears
exactly when it matters.

## Consequences

**Good**

- The disclosure hole is closed, and eleven tests exist so it stays closed.
- Adding a role is one enum case, not a sweep through the policies.
- A read-only integration token cannot cancel a subscription.

**Bad, and accepted**

- Every existing feature test now authenticates. That is real churn, and it is
  the cost of the endpoints no longer being open.
- `users.customer_id` cannot express "required for customer-role only" in the
  schema without a check constraint that needs editing per role. The model
  enforces it; the database does not.

**Deliberately absent**

- **Registration, password reset, email verification.** This module issues
  tokens for accounts that already exist. Provisioning belongs with whatever
  owns the customer lifecycle, and stubbing it here would be inventing a
  product decision.
- **Refresh tokens.** Re-authenticating against a 12-hour token is one request.
  A refresh flow is a second credential to leak in exchange for saving it.
- **Per-endpoint abilities.** `subscriptions:read` and `subscriptions:write`
  are the granularity people set correctly. An ability nobody can explain is an
  ability nobody sets right.

**Revisit if**

- A first-party SPA on the same domain arrives — then `stateful` is populated
  and the CSRF path is turned on deliberately, not by leftover default.
- Customers need to delegate scoped access to third parties. That is OAuth, and
  Passport rather than Sanctum.
