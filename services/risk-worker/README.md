# Risk worker

Scores delinquency risk from billing payment failures.

Consumes `PaymentFailed` from the billing core over Redis, scores it, and
publishes the result back. Retries, dead-lettering and idempotency by message
id — the shape the roadmap specified before either side existed.

## Why a list and not pub/sub

Decided in the billing core's [ADR-0003](../../docs/adr/0003-redis-for-queue-cache-and-locks.md),
before this worker was written: Redis pub/sub has no delivery guarantee, so a
subscriber that happens to be restarting when a message is published never sees
it. A risk signal that silently vanishes is worse than none.

## Delivery guarantees

**At-least-once.** `BLMOVE events -> processing` claims a message atomically;
it leaves `processing` only once handled. A worker killed mid-message leaves it
there and `recover_stale()` returns it — so the failure mode is a duplicate,
never a loss.

Scoring is pure. A Redis Lua operation atomically publishes its result, writes
`risk:completed:<message_id>` with a seven-day TTL, and acknowledges the input.
A crash before that operation leaves a recoverable message; an ambiguous reply
after commit cannot publish twice. Legacy `risk:seen:` in-progress markers are
not treated as proof of completion.

| Key | Role |
| --- | --- |
| `risk:events` | inbound, written by Laravel |
| `risk:events:processing` | in flight |
| `risk:events:dead` | dead letters, original payload preserved |
| `risk:scores` | outbound, read by Laravel |
| `risk:completed:<id>` | committed-result deduplication, 7-day TTL |

Malformed messages are dead-lettered **without** retrying — a payload that is
not valid JSON will not become valid on the third attempt. Transient failures
retry up to `RISK_MAX_ATTEMPTS`, then dead-letter with the error attached.

## The scoring model

Deliberately simple and explainable — a score a support agent cannot account
for is a score nobody trusts, so every contribution comes back named:

```json
"factors": [
  {"code": "reason:expired_card", "points": 60},
  {"code": "repeat_attempts", "points": 24},
  {"code": "no_further_retries", "points": 10}
]
```

Two properties worth knowing:

- **An expired card outranks a flat decline**, not because it is worse but
  because it is the failure no retry can fix. Insufficient funds ranks below a
  decline because it usually clears on payday.
- **`invalid_request` always scores zero.** That is our bug, never the
  customer's risk, and no combination of the other inputs changes it.

An unrecognised reason from a future producer scores conservatively rather than
being dropped — the failure happened, we just do not know what kind.

## Running it

```sh
pip install -e ".[dev]"
python -m pytest          # needs a Redis; REDIS_URL defaults to localhost
python -m mypy src tests
python -m ruff check .
python -m risk_worker
```

Requires Python 3.12. From the repository root, `docker compose up risk-worker`.

The consumer tests run against a **real Redis**, not a fake. Atomic claim,
idempotency under a race and orphan recovery are properties of Redis itself; a
mock would only prove the mock behaves as its author imagined.

## Configuration

Every value has a default that works against the compose stack, so the worker
starts with nothing set. `REDIS_URL`, `RISK_EVENTS_KEY`, `RISK_SCORES_KEY`,
`RISK_MAX_ATTEMPTS`, `RISK_BLOCK_SECONDS`, `RISK_STALE_AFTER`,
`RISK_IDEMPOTENCY_TTL`. The key names must match `config/risk.php` in the
billing core.

Laravel claims returned scores into `risk:scores:processing` and acknowledges
only after persistence or intentional validation rejection. A database failure
leaves the claimed message for the next drain.

Non-UTF-8 dead letters preserve the original bytes as base64 with
`payload_encoding: "base64"`. Text payloads use `payload_encoding: "utf-8"`.
Custom event keys derive their processing/dead-letter keys unless explicitly
overridden. Block time, max attempts and deduplication TTL must be positive.
