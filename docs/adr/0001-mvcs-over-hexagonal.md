# ADR-0001 — MVCS with an enforced pure core, not hexagonal architecture

- **Status:** accepted
- **Date:** 2026-08
- **Applies to:** the whole application

## Context

The subscriptions domain has one aggregate, three entities and a handful of
genuinely interesting rules (proration, status transitions, trial conversion).
The obvious candidates were:

1. **Hexagonal / ports and adapters** — domain layer with repository interfaces,
   an application layer of use cases, Eloquent confined to infrastructure.
2. **DDD tactical** — the above plus aggregates, domain events, specifications
   and CQRS.
3. **Laravel MVCS** — controllers, services, models, resources.

## Decision

Option 3, plus one enforced boundary: the parts of the domain that are pure
arithmetic — `Money`, the enums, `ProrationCalculator`, the DTOs — import
nothing from `Illuminate`, and an architecture test fails the pipeline if they
ever do.

## Why

Hexagonal buys the ability to swap an implementation. Counting honestly, this
application would have gained three interfaces with exactly one implementation
each and no plausible second one: there is no second ORM coming, no second
database, no second HTTP layer. An abstraction with one implementation is not
decoupling, it is indirection — and indirection you cannot justify reads, to a
reviewer, as cargo cult.

What the layering was actually *for* was two properties, and both are available
without it:

- **Business rules in one place.** `SubscriptionService` is the only thing in
  the codebase that mutates a subscription. Controllers, jobs and console
  commands all go through it.
- **Rules testable without infrastructure.** Achieved by the pure-core boundary
  rather than by a full hexagon. `ProrationCalculator` needs no container at all
  — `make domain-check` runs it with no vendor directory.

The cost of MVCS is real and worth naming: `Plan::query()` appears inside
`SubscriptionService`, so the service is coupled to Eloquent. If this project
ever needed to run its pricing rules outside Laravel, that coupling is the thing
that would have to be undone.

## Consequences

**Good**

- A Laravel developer finds anything in ten seconds.
- The interesting logic is unit tested in milliseconds.
- Roughly a third fewer files than the hexagonal version, with no rule lost.
- The boundary that matters is machine-enforced, not documented and hoped for.

**Bad**

- Services know about Eloquent. Swapping the ORM would touch the service layer.
- Nothing structurally stops a future contributor putting a query in a
  controller. `tests/Arch.php` catches the common cases; it is not a proof.

**Revisit if**

- A second bounded context needs to share the domain rules without sharing the
  database.
- The rules need to run somewhere Laravel does not, such as inside the Node
  pricing service.

## Alternatives, and why not

**Hexagonal everywhere** — the extra layers would each need a justification this
domain cannot supply. An interface with one implementation invites the question
"what is the second one?", and having no answer is worse than not having built
it.

**DDD tactical with CQRS** — the read and write models are the same shape.
Splitting them would be sophistication with no problem attached.

**Hexagonal in `Subscriptions` only, MVCS elsewhere** — genuinely defensible,
and it was close. Rejected because a codebase with two architectures needs every
reader to learn which one they are in, and the pure-core boundary already
delivers the testability that was the point.
