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

Duplicates are then absorbed by idempotency, which is why that has to exist
before this pattern is safe: at-least-once delivery plus a non-idempotent
handler is just data corruption with extra steps.

## Attempts and the dead letter

Attempt counts live in a Redis hash keyed by ``message_id``, not in the payload
— rewriting the message on each retry would change the bytes and defeat any
downstream deduplication. After ``max_attempts`` the message goes to the
dead-letter list with the last error attached, and is never retried again.

Malformed messages skip retries entirely: a payload that is not valid JSON will
not become valid on the third attempt.
"""

from __future__ import annotations

import json
import logging
import time
from dataclasses import dataclass
from typing import cast

from redis import Redis

from .config import Config
from .envelope import MalformedMessage, PaymentFailedEvent, parse
from .scoring import RiskAssessment, score_payment_failure

logger = logging.getLogger("risk_worker")


@dataclass(frozen=True, slots=True)
class HandledResult:
    """What one iteration of the loop did. Returned so tests can assert on it."""

    message_id: str | None
    outcome: str  # "scored" | "duplicate" | "dead_lettered" | "retried" | "idle"


class Consumer:
    def __init__(self, redis: Redis, config: Config) -> None:
        self._redis = redis
        self._config = config

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
            self._ack(raw)
            return HandledResult(message_id=None, outcome="dead_lettered")

        if not self._claim_idempotency(event.message_id):
            logger.info(
                "skipping already-processed message",
                extra={"message_id": event.message_id},
            )
            self._ack(raw)
            return HandledResult(message_id=event.message_id, outcome="duplicate")

        try:
            assessment = self._score(event)
        except Exception as exc:
            return self._handle_failure(raw, event, exc)

        self._publish(event, assessment)
        self._ack(raw)
        self._clear_attempts(event.message_id)

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
        since = cast("str | None", self._redis.get(marker))

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
        while self._redis.rpoplpush(
            self._config.processing_key, self._config.events_key
        ):
            recovered += 1

        self._redis.delete(marker)
        logger.warning("recovered %d stale message(s)", recovered)

        return recovered

    # --------------------------------------------------------------- private

    def _claim(self) -> str | None:
        return cast(
            "str | None",
            self._redis.blmove(
                self._config.events_key,
                self._config.processing_key,
                timeout=self._config.block_seconds,
                src="RIGHT",
                dest="LEFT",
            ),
        )

    def _ack(self, raw: str) -> None:
        """Remove exactly one copy of this message from the processing list."""
        self._redis.lrem(self._config.processing_key, 1, raw)

    def _score(self, event: PaymentFailedEvent) -> RiskAssessment:
        return score_payment_failure(
            reason=event.reason,
            attempt_number=event.attempt_number,
            will_retry=event.will_retry,
            amount_minor=event.amount_minor,
        )

    def _publish(self, event: PaymentFailedEvent, assessment: RiskAssessment) -> None:
        self._redis.rpush(
            self._config.scores_key,
            json.dumps(
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
            ),
        )

    def _handle_failure(
        self, raw: str, event: PaymentFailedEvent, exc: Exception
    ) -> HandledResult:
        attempts = self._record_attempt(event.message_id)

        # The idempotency claim is released so the retry is allowed to run.
        # Holding it would mean a transient failure permanently swallowed the
        # message — the exact opposite of what idempotency is for.
        self._release_idempotency(event.message_id)

        if attempts >= self._config.max_attempts:
            logger.error(
                "dead-lettering after %d attempts: %s", attempts, exc, exc_info=exc
            )
            self._dead_letter(raw, reason=str(exc), attempts=attempts)
            self._ack(raw)
            self._clear_attempts(event.message_id)
            return HandledResult(message_id=event.message_id, outcome="dead_lettered")

        logger.warning("returning message for retry (attempt %d): %s", attempts, exc)
        self._redis.rpush(self._config.events_key, raw)
        self._ack(raw)

        return HandledResult(message_id=event.message_id, outcome="retried")

    def _dead_letter(self, raw: str, *, reason: str, attempts: int) -> None:
        self._redis.rpush(
            self._config.dead_letter_key,
            json.dumps(
                {
                    "failed_at": int(time.time()),
                    "attempts": attempts,
                    "error": reason,
                    # The original bytes, untouched. A dead letter you cannot
                    # replay is a log line with extra steps.
                    "payload": raw,
                },
                separators=(",", ":"),
            ),
        )

    # -- idempotency ------------------------------------------------------

    def _idempotency_key(self, message_id: str) -> str:
        return f"risk:seen:{message_id}"

    def _claim_idempotency(self, message_id: str) -> bool:
        """True if this worker won the right to process the message.

        SET NX is atomic, so two workers racing on a redelivered message cannot
        both proceed.
        """
        return bool(
            self._redis.set(
                self._idempotency_key(message_id),
                1,
                nx=True,
                ex=self._config.idempotency_ttl_seconds,
            )
        )

    def _release_idempotency(self, message_id: str) -> None:
        self._redis.delete(self._idempotency_key(message_id))

    # -- attempts ---------------------------------------------------------

    def _attempts_key(self) -> str:
        return f"{self._config.events_key}:attempts"

    def _record_attempt(self, message_id: str) -> int:
        return cast(int, self._redis.hincrby(self._attempts_key(), message_id, 1))

    def _clear_attempts(self, message_id: str) -> None:
        self._redis.hdel(self._attempts_key(), message_id)
