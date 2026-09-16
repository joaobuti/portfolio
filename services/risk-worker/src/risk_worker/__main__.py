"""Entry point: loop, log as JSON, shut down cleanly."""

from __future__ import annotations

import json
import logging
import signal
import sys
from types import FrameType

from redis import Redis
from redis.exceptions import RedisError

from .config import Config
from .consumer import Consumer


class JsonFormatter(logging.Formatter):
    """One JSON object per line.

    The billing core logs its activity channel the same way, and for the same
    reason: `correlation_id` has to be a searchable field in an aggregator, not
    a substring buried in prose. It is what makes one customer action traceable
    across PHP and Python.
    """

    _RESERVED = frozenset(logging.LogRecord("", 0, "", 0, "", None, None).__dict__)

    def format(self, record: logging.LogRecord) -> str:
        payload = {
            "level": record.levelname.lower(),
            "message": record.getMessage(),
            "logger": record.name,
        }

        # Anything passed via `extra=` rides along as a top-level field.
        for key, value in record.__dict__.items():
            if key not in self._RESERVED and not key.startswith("_"):
                payload[key] = value

        if record.exc_info:
            payload["exception"] = self.formatException(record.exc_info)

        return json.dumps(payload, separators=(",", ":"), default=str)


def configure_logging() -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonFormatter())
    logging.basicConfig(level=logging.INFO, handlers=[handler], force=True)


def main() -> int:
    configure_logging()
    logger = logging.getLogger("risk_worker")

    config = Config.from_env()
    redis = Redis.from_url(config.redis_url, decode_responses=True)

    consumer = Consumer(redis, config)
    running = True

    def stop(signum: int, _frame: FrameType | None) -> None:
        # Flip a flag rather than exiting here. BLMOVE has already claimed a
        # message by the time a signal can arrive mid-iteration; tearing the
        # process down would leave it in the processing list for the stale
        # recovery to clean up, which works but is noise we can avoid.
        nonlocal running
        running = False
        logger.info("shutting down", extra={"signal": signum})

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)

    logger.info(
        "risk worker started",
        extra={"events_key": config.events_key, "scores_key": config.scores_key},
    )

    while running:
        try:
            consumer.recover_stale()
            consumer.run_once()
        except RedisError as exc:
            # Redis being briefly unreachable is an operational fact, not a
            # reason to exit and have the orchestrator restart us in a loop.
            logger.error("redis error, continuing", exc_info=exc)
        except Exception as exc:
            logger.error("unexpected error, continuing", exc_info=exc)

    logger.info("risk worker stopped")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
