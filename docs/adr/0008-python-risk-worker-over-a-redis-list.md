# ADR-0008: The risk worker consumes a Redis list, and the messaging keys carry no prefix

**Status** Accepted
**Date** 2026-09-03

## Context

The Python risk worker has been in the roadmap since the first commit and
blocked the whole time: it consumes `PaymentFailed`, and until ADR-0007 nothing
produced that event.

Two decisions had already been made for it before it existed, and both held up.
ADR-0003 said the transport would be a **list, not a pub/sub channel**. The
correlation-id middleware was built early so an id would already be flowing by
the time a second language needed one.

## Decision

**A separate Python service, consuming a Redis list with at-least-once
delivery and idempotency by message id.**

### The transport

`BLMOVE events -> processing` claims a message atomically; it leaves the
processing list only once handled. A worker killed mid-message leaves it there
and `recover_stale()` returns it — so the failure mode is a **duplicate, never
a loss**.

Scoring is pure; only completion needs coordination. A Lua operation atomically
publishes the score, stores `risk:completed:<message_id>` with its TTL, and
acknowledges the input. Duplicates therefore cannot publish twice.

**Implementation correction (2026-11-21):** The earlier pre-scoring
`risk:seen:` claim could survive a crash before publication and cause recovery
to discard unfinished work. Completed markers use a separate namespace so
legacy claims cannot be mistaken for successfully published results.

### Malformed messages are not retried

A payload that is not valid JSON will not become valid on the third attempt.
It is dead-lettered immediately, with the **original bytes preserved** — a dead
letter you cannot replay is a log line with extra steps. Transient failures do
retry, up to `RISK_MAX_ATTEMPTS`.

Retry/dead-letter movement and input acknowledgment are atomic. Only successful
publication writes the completed marker. Redis transport errors leave any
unacknowledged input available for stale recovery. Non-UTF-8 payloads retain
their exact original bytes in base64, labeled with `payload_encoding`.

### The envelope is the contract

`message_id` for deduplication, `correlation_id` carried through, amounts as
**integers in minor units** (ADR-0002, and the rule most likely to be broken by
a JSON boundary), and `reason` as our mapped enum rather than a gateway string.

The worker's parser rejects a float amount rather than coercing it: a value
that arrived as `100.0` already lost a guarantee upstream, and coercing it here
would launder that bug into a risk score.

`attempt_number` is carried rather than derived, so the worker never queries
back into Postgres and couples itself to our schema.

### The messaging keys carry no Redis prefix

**This is the decision that was nearly a silent production bug.** Laravel
prefixes every Redis key with `Str::slug(APP_NAME).'-database-'`, which is
right for its own keys — cache entries, queues, locks — so several apps can
share one Redis. It is wrong for a key another service reads.

Publishing to `portfolio-billing-database-risk:events` while the worker blocks
on `risk:events` means **nothing is ever delivered, and both test suites pass**.
The PHP tests asserted against a prefixed key they had also written; the Python
tests used a real Redis with keys they controlled. Neither could see it.

It was found by publishing from PHP and consuming from Python against one
Redis, which is now the reason a dedicated `messaging` connection with
`'prefix' => ''` exists in `config/database.php` rather than a comment asking
people to remember.

### The scoring model is explainable

Every contribution is named and returned. A score a support agent cannot
account for is a score nobody trusts.

Two properties worth stating: an **expired card outranks a flat decline**, not
because it is worse but because it is the failure no retry can fix; and
`invalid_request` **always scores zero**, because that is our bug and never the
customer's risk, whatever the other inputs say.

An unrecognised reason from a future producer scores conservatively rather than
being dropped — the failure happened, we just do not know what kind.

### The return leg is a scheduled reliable drain, not a second daemon

Volume is bounded by the number of failed payments. `risk:consume-scores` runs
every five minutes and is defensive at every step: the producer is another
service on its own deploy cycle, so an unparseable message, an unknown customer
or a band this version has never heard of is dropped and logged rather than
allowed to abort the drain and strand everything behind it.

The drain claims scores into a processing list under a shared Redis lock and
acknowledges only after a database save or intentional validation rejection.
An interrupted drain retries its claimed head on its next run. Customer UUIDs
are validated before querying Postgres.

Scores land on `customers`, not in a history table. The question asked is "how
risky is this customer now?", and one row per failure would be a table nobody
queries except to take its last row — the audit trail of *why* already exists
in `charge_attempts`.

## Consequences

**Good**

- A customer action is now traceable from an HTTP request, through Redis, into
  a Python process and back, on one correlation id.
- The worker scales horizontally with no coordination: claims are atomic and
  handling is idempotent.
- Both services boot with no configuration at all, so `docker compose up` works
  on a fresh clone.

**Bad, and accepted**

- Two repositories must agree on the key names. Mitigated by both reading them
  from the environment and by the compose file setting them in one place, but
  it is still a contract nothing type-checks.
- The scoring model is hand-tuned constants, not learned from data. That is
  honest for a module with no historical outcomes to learn from, and the
  factors are returned so the numbers can be argued with.
- Dead letters accumulate in a list nothing drains. Deliberate: a dead letter
  should be looked at by a person, and auto-expiring them would hide the bug
  that produced them.

**Revisit if**

- Ordering across a customer's failures starts to matter, or more than one
  consumer needs the same events. Then Redis Streams with consumer groups,
  which give both — at the cost of the simplicity that made a list the right
  first answer.
