"""Configuration, read once at startup.

Every value has a default that works against the compose stack, so the worker
starts with no environment set at all — the same property the billing core has
with its own `.env.example`.

The defaults live in module constants rather than as class attributes read back
off the dataclass. With ``slots=True`` the decorator replaces class attributes
with slot descriptors, so ``Config.redis_url`` returns a descriptor object
rather than the string — a bug that never fires in a test that builds a Config
explicitly, and fires immediately in production.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from typing import Final

DEFAULT_REDIS_URL: Final = "redis://redis:6379/0"

# Must match config/risk.php in the billing core. Two repositories agreeing on
# a value is easiest when both read it from the environment.
DEFAULT_EVENTS_KEY: Final = "risk:events"
DEFAULT_SCORES_KEY: Final = "risk:scores"

#: Where in-flight messages live between being claimed and being acked. The
#: reliable-queue pattern: a crash leaves the message here rather than dropping
#: it, and `recover_stale` moves it back.
DEFAULT_PROCESSING_KEY: Final = "risk:events:processing"

#: Messages that could not be handled after `max_attempts`.
DEFAULT_DEAD_LETTER_KEY: Final = "risk:events:dead"

#: Seconds BLMOVE blocks before returning empty. Bounded rather than infinite
#: so the loop can notice a shutdown signal.
DEFAULT_BLOCK_SECONDS: Final = 5

DEFAULT_MAX_ATTEMPTS: Final = 3

#: How long a processed message_id is remembered. Long enough to cover any
#: plausible redelivery, short enough that the keyspace does not grow forever.
DEFAULT_IDEMPOTENCY_TTL_SECONDS: Final = 7 * 24 * 3600

#: A message sitting in the processing list longer than this is assumed
#: orphaned by a dead worker and is recovered.
DEFAULT_STALE_AFTER_SECONDS: Final = 300


def _int_env(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or raw.strip() == "":
        return default
    try:
        return int(raw)
    except ValueError as exc:
        raise ValueError(f"{name} must be an integer, got {raw!r}") from exc


@dataclass(frozen=True, slots=True)
class Config:
    redis_url: str = DEFAULT_REDIS_URL
    events_key: str = DEFAULT_EVENTS_KEY
    scores_key: str = DEFAULT_SCORES_KEY
    processing_key: str = DEFAULT_PROCESSING_KEY
    dead_letter_key: str = DEFAULT_DEAD_LETTER_KEY
    block_seconds: int = DEFAULT_BLOCK_SECONDS
    max_attempts: int = DEFAULT_MAX_ATTEMPTS
    idempotency_ttl_seconds: int = DEFAULT_IDEMPOTENCY_TTL_SECONDS
    stale_after_seconds: int = DEFAULT_STALE_AFTER_SECONDS

    @staticmethod
    def from_env() -> Config:
        return Config(
            redis_url=os.environ.get("REDIS_URL", DEFAULT_REDIS_URL),
            events_key=os.environ.get("RISK_EVENTS_KEY", DEFAULT_EVENTS_KEY),
            scores_key=os.environ.get("RISK_SCORES_KEY", DEFAULT_SCORES_KEY),
            processing_key=os.environ.get("RISK_PROCESSING_KEY", DEFAULT_PROCESSING_KEY),
            dead_letter_key=os.environ.get("RISK_DEAD_LETTER_KEY", DEFAULT_DEAD_LETTER_KEY),
            block_seconds=_int_env("RISK_BLOCK_SECONDS", DEFAULT_BLOCK_SECONDS),
            max_attempts=_int_env("RISK_MAX_ATTEMPTS", DEFAULT_MAX_ATTEMPTS),
            idempotency_ttl_seconds=_int_env(
                "RISK_IDEMPOTENCY_TTL", DEFAULT_IDEMPOTENCY_TTL_SECONDS
            ),
            stale_after_seconds=_int_env("RISK_STALE_AFTER", DEFAULT_STALE_AFTER_SECONDS),
        )
