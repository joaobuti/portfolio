"""The consumer loop.

## Why a list and not pub/sub

Decided in the billing core's ADR-0003, before this worker existed: Redis
pub/sub has no delivery guarantee, so a subscriber that happens to be
restarting when a message is published never sees it — and a risk signal that
silently vanishes is worse than none. A list is durable.

## The reliable-queue pattern

``BLMOVE events -> processing`` claims a message atomically. It is removed from
``processing`` only after it has been handled. A worker killed mid-message
leaves it there, and :func:`recover_stale` moves it back — so the failure mode
is a duplicate delivery, never a lost one.

Scoring is pure. Publishing the result, recording completion and acknowledging
the message are one Redis operation. A crash before it leaves recoverable work;
a duplicate delivery after it cannot publish a second result.

## Attempts and the dead letter

Attempt counts live in a Redis hash keyed by ``message_id``, not in the payload
— rewriting the message on each retry would change the bytes and defeat any
downstream deduplication. After ``max_attempts`` the message goes to the
dead-letter list with the last error attached, and is never retried again.

Malformed messages skip retries entirely: a payload that is not valid JSON will
not become valid on the third attempt.
"""

from __future__ import annotations

import base64
import json
import logging
import time
from dataclasses import dataclass
from typing import Protocol, cast

from redis import Redis
from redis.exceptions import RedisError

from .config import Config
from .envelope import MalformedMessage, PaymentFailedEvent, parse
from .scoring import RiskAssessment, score_payment_failure

logger = logging.getLogger("risk_worker")


class RedisCommand(Protocol):
    def __call__(self, *args: str | bytes | int) -> object: ...


@dataclass(frozen=True, slots=True)
class HandledResult:
    """What one iteration of the loop did. Returned so tests can assert on it."""

    message_id: str | None
    outcome: str  # "scored" | "duplicate" | "dead_lettered" | "retried" | "idle"


class Consumer:
    def __init__(self, redis: Redis, config: Config) -> None:
        self._redis = redis
        self._config = config
        # redis-py 5 exposes this binary-safe command API without annotations.
        self._execute = cast(RedisCommand, redis.execute_command)

    # ---------------------------------------------------------------- public

    def run_once(self) -> HandledResult:
        """Claim and handle at most one message.

        Returns rather than looping so the caller owns the shutdown check, and
        so tests can step the worker one message at a time.
        """
        raw = self._claim()

        if raw is None:
            return HandledResult(message_id=None, outcome="idle")

        try:
            event = parse(raw)
        except MalformedMessage as exc:
            # Not retried: it will not parse on the third attempt either.
            logger.warning("dead-lettering malformed message: %s", exc)
            self._dead_letter(raw, reason=str(exc), attempts=1)
            return HandledResult(message_id=None, outcome="dead_lettered")

        try:
            assessment = self._score(event)
            published = self._publish(event, assessment, raw)
        except RedisError:
            # An ambiguous transport failure may have committed the Lua script.
            # Leave any unacknowledged message for recovery; never remove a
            # completed marker or invent another publication.
            raise
        except Exception as exc:
            return self._handle_failure(raw, event, exc)

        if not published:
            return HandledResult(message_id=event.message_id, outcome="duplicate")

        logger.info(
            "scored payment failure",
            extra={
                "message_id": event.message_id,
                "customer_uuid": event.customer_uuid,
                "correlation_id": event.correlation_id,
                "score": assessment.score,
            },
        )

        return HandledResult(message_id=event.message_id, outcome="scored")

    def recover_stale(self) -> int:
        """Move messages orphaned by a dead worker back onto the events list.

        Conservative by design: it only runs when the processing list is older
        than ``stale_after_seconds``, tracked by a single timestamp key. Moving
        messages back too eagerly would duplicate work that is still in flight
        on a healthy worker.
        """
        marker = f"{self._config.processing_key}:since"
        since = cast("str | bytes | None", self._redis.get(marker))

        # redis-py types every command as `sync | Awaitable` because one class
        # serves both clients. This client is synchronous; the casts say so
        # once rather than sprinkling `# type: ignore` across the file.
        depth = cast(int, self._redis.llen(self._config.processing_key))
        if depth == 0:
            self._redis.delete(marker)
            return 0

        now = int(time.time())

        if since is None:
            self._redis.set(marker, now)
            return 0

        if now - int(since) < self._config.stale_after_seconds:
            return 0

        recovered = 0
        while self._redis.rpoplpush(self._config.processing_key, self._config.events_key):
            recovered += 1

        self._redis.delete(marker)
        logger.warning("recovered %d stale message(s)", recovered)

        return recovered

    # --------------------------------------------------------------- private

    def _claim(self) -> str | bytes | None:
        return cast(
            "str | bytes | None",
            self._redis.blmove(
                self._config.events_key,
                self._config.processing_key,
                timeout=self._config.block_seconds,
                src="RIGHT",
                dest="LEFT",
            ),
        )

    def _score(self, event: PaymentFailedEvent) -> RiskAssessment:
        return score_payment_failure(
            reason=event.reason,
            attempt_number=event.attempt_number,
            will_retry=event.will_retry,
            amount_minor=event.amount_minor,
        )

    def _publish(
        self, event: PaymentFailedEvent, assessment: RiskAssessment, raw: str | bytes
    ) -> bool:
        # Scoring is pure. Only committing its result needs coordination:
        # publish, remember completion and acknowledge in one Redis operation.
        payload = json.dumps(
            {
                "event": "risk.scored",
                # Echoed so the consumer on the other side can deduplicate
                # against the same key we did.
                "in_reply_to": event.message_id,
                "correlation_id": event.correlation_id,
                "data": {
                    "customer_uuid": event.customer_uuid,
                    "invoice_uuid": event.invoice_uuid,
                    "score": assessment.score,
                    "band": assessment.band.value,
                    # Named contributions, so a support agent can account
                    # for the number rather than trust it.
                    "factors": [
                        {"code": factor.code, "points": factor.points}
                        for factor in assessment.factors
                    ],
                },
            },
            separators=(",", ":"),
        )
        return bool(
            self._execute(
                "EVAL",
                """
            if redis.call('EXISTS', KEYS[1]) == 1 then
                redis.call('LREM', KEYS[3], 1, ARGV[2])
                return 0
            end
            redis.call('RPUSH', KEYS[2], ARGV[1])
            redis.call('SET', KEYS[1], '1', 'EX', ARGV[3])
            redis.call('LREM', KEYS[3], 1, ARGV[2])
            redis.call('HDEL', KEYS[4], ARGV[4])
            return 1
            """,
                4,
                self._idempotency_key(event.message_id),
                self._config.scores_key,
                self._config.processing_key,
                self._attempts_key(),
                payload,
                raw,
                self._config.idempotency_ttl_seconds,
                event.message_id,
            )
        )

    def _handle_failure(
        self, raw: str | bytes, event: PaymentFailedEvent, exc: Exception
    ) -> HandledResult:
        attempts = self._record_attempt(event.message_id)

        if attempts >= self._config.max_attempts:
            logger.error("dead-lettering after %d attempts: %s", attempts, exc, exc_info=exc)
            self._dead_letter(raw, reason=str(exc), attempts=attempts)
            self._clear_attempts(event.message_id)
            return HandledResult(message_id=event.message_id, outcome="dead_lettered")

        logger.warning("returning message for retry (attempt %d): %s", attempts, exc)
        self._move(raw, self._config.events_key, raw)

        return HandledResult(message_id=event.message_id, outcome="retried")

    def _dead_letter(self, raw: str | bytes, *, reason: str, attempts: int) -> None:
        encoding = "utf-8"
        if isinstance(raw, bytes):
            try:
                payload = raw.decode("utf-8")
            except UnicodeDecodeError:
                payload = base64.b64encode(raw).decode("ascii")
                encoding = "base64"
        else:
            payload = raw
        dead = json.dumps(
            {
                "failed_at": int(time.time()),
                "attempts": attempts,
                "error": reason,
                "payload": payload,
                "payload_encoding": encoding,
            },
            separators=(",", ":"),
        )
        self._move(raw, self._config.dead_letter_key, dead)

    def _move(self, raw: str | bytes, destination: str, payload: str | bytes) -> None:
        self._execute(
            "EVAL",
            "redis.call('RPUSH', KEYS[2], ARGV[1]); "
            "return redis.call('LREM', KEYS[1], 1, ARGV[2])",
            2,
            self._config.processing_key,
            destination,
            payload,
            raw,
        )

    # -- idempotency ------------------------------------------------------

    def _idempotency_key(self, message_id: str) -> str:
        return f"risk:completed:{message_id}"

    # -- attempts ---------------------------------------------------------

    def _attempts_key(self) -> str:
        return f"{self._config.events_key}:attempts"

    def _record_attempt(self, message_id: str) -> int:
        return cast(int, self._redis.hincrby(self._attempts_key(), message_id, 1))

    def _clear_attempts(self, message_id: str) -> None:
        self._redis.hdel(self._attempts_key(), message_id)
