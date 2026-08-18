# ADR-0003 — One Redis, three distinct jobs

- **Status:** accepted
- **Date:** 2026-08

## Context

The module needs asynchronous work (renewals), a cache (the plan catalogue) and
mutual exclusion across processes (nothing may mutate one subscription twice at
once). Postgres could serve all three: `LISTEN/NOTIFY` or a jobs table, a
materialised view, and advisory locks.

## Decision

Redis for all three, with the three roles named and kept conceptually separate.

## Why

**Queue.** Laravel's Redis queue driver gives delayed jobs, per-job retry
policy, `ShouldBeUnique` and a failed-jobs table with no extra infrastructure.
The database driver would work, but renewals are the one workload guaranteed to
spike — the whole customer base has an anniversary somewhere — and putting that
spike on the same Postgres instance that serves the API is how a billing run
takes the product down.

Two named queues, not one: `billing` and `observability`. A backlog of audit
logging must never delay a renewal.

**Cache.** The plan catalogue is read on nearly every request and changes a few
times a year. What matters is not the cache but the invalidation: it is
**event-driven, via a model observer on write**, not TTL-expired. A TTL forces a
choice between serving a stale price and hammering the database; an observer
avoids the choice. The TTL that is configured is only a safety net against a
missed flush.

**Lock.** `Cache::lock()` on Redis serialises mutations of a single subscription
across web nodes and queue workers alike, which a per-process mutex cannot do
and a database transaction only does at the row level — too late, because the
proration is computed before the write.

## Consequences

**Good**

- Renewal spikes never compete with API traffic for database connections.
- One operational dependency covering three needs.
- Locks work identically in a request, a job and a console command.

**Bad**

- Redis is now on the critical path. If it is down, the API still serves reads
  (Laravel falls through to the database on a cache miss) but plan changes fail,
  because the lock cannot be acquired. **That is deliberate** — failing a plan
  change is recoverable; billing one twice is not.
- Redis persistence is `appendonly yes`, but a queue on Redis is still less
  durable than a queue in Postgres. Acceptable because the jobs are idempotent
  and the nightly sweep re-queues anything that was lost.
- Locks have a TTL, so a worker that hangs for longer than `LOCK_TTL` can have
  its lock stolen. This is why `renew()` is idempotent by construction rather
  than trusting the lock.

**Revisit if**

- Message durability ever matters more than throughput — then RabbitMQ or SQS,
  with proper acknowledgements.
- Cross-service messaging arrives (the Python risk worker). Redis pub/sub has no
  delivery guarantee; the worker will consume a list, not a channel.
