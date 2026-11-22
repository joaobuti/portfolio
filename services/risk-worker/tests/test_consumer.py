"""Consumer behaviour, against a real Redis.

Not a fake. Every property under test here — atomic claim, idempotency under a
race, recovery of an orphaned message — is a property of Redis itself, and a
mock would only prove that the mock behaves as the author imagined.
"""

from __future__ import annotations

import json
import os
from collections.abc import Iterator
from typing import cast

import pytest
from redis import Redis

from risk_worker.config import Config
from risk_worker.consumer import Consumer


@pytest.fixture
def redis() -> Iterator[Redis]:
    client = Redis.from_url(
        os.environ.get("REDIS_URL", "redis://127.0.0.1:6379/15"),
        decode_responses=True,
    )
    client.flushdb()
    yield client
    client.flushdb()


@pytest.fixture
def config() -> Config:
    return Config(
        events_key="t:events",
        scores_key="t:scores",
        processing_key="t:processing",
        dead_letter_key="t:dead",
        block_seconds=1,
        max_attempts=2,
        stale_after_seconds=0,
    )


@pytest.fixture
def consumer(redis: Redis, config: Config) -> Consumer:
    return Consumer(redis, config)


def message(message_id: str = "msg-1", **data_overrides: object) -> str:
    data: dict[str, object] = {
        "invoice_uuid": "inv-1",
        "customer_uuid": "cus-1",
        "subscription_uuid": "sub-1",
        "amount_minor": 10_001,
        "currency": "BRL",
        "reason": "expired_card",
        "attempt_number": 2,
        "will_retry": False,
    }
    data.update(data_overrides)

    return json.dumps(
        {
            "message_id": message_id,
            "event": "payment.failed",
            "occurred_at": "2026-09-02T09:00:00+00:00",
            "correlation_id": "corr-1",
            "data": data,
        }
    )


class TestHappyPath:
    def test_scores_a_message_and_publishes_the_result(
        self, redis: Redis, config: Config, consumer: Consumer
    ) -> None:
        redis.rpush(config.events_key, message())

        result = consumer.run_once()

        assert result.outcome == "scored"

        published = json.loads(cast(str, redis.lpop(config.scores_key)))
        assert published["event"] == "risk.scored"
        assert published["in_reply_to"] == "msg-1"
        assert published["correlation_id"] == "corr-1"
        assert published["data"]["customer_uuid"] == "cus-1"
        assert published["data"]["band"] == "high"
        assert published["data"]["factors"]

    def test_acknowledges_the_message(
        self, redis: Redis, config: Config, consumer: Consumer
    ) -> None:
        redis.rpush(config.events_key, message())

        consumer.run_once()

        # Nothing left anywhere: not queued, not in flight.
        assert redis.llen(config.events_key) == 0
        assert redis.llen(config.processing_key) == 0

    def test_returns_idle_when_there_is_nothing_to_do(self, consumer: Consumer) -> None:
        assert consumer.run_once().outcome == "idle"


class TestIdempotency:
    def test_a_redelivered_message_is_not_scored_twice(
        self, redis: Redis, config: Config, consumer: Consumer
    ) -> None:
        # At-least-once delivery is the guarantee; this is what makes it safe.
        redis.rpush(config.events_key, message())
        redis.rpush(config.events_key, message())

        first = consumer.run_once()
        second = consumer.run_once()

        assert first.outcome == "scored"
        assert second.outcome == "duplicate"
        assert redis.llen(config.scores_key) == 1

    def test_distinct_messages_are_both_scored(
        self, redis: Redis, config: Config, consumer: Consumer
    ) -> None:
        redis.rpush(config.events_key, message("msg-1"))
        redis.rpush(config.events_key, message("msg-2"))

        consumer.run_once()
        consumer.run_once()

        assert redis.llen(config.scores_key) == 2


class TestMalformed:
    def test_dead_letters_immediately_without_retrying(
        self, redis: Redis, config: Config, consumer: Consumer
    ) -> None:
        # It will not parse on the third attempt either.
        redis.rpush(config.events_key, "not json")

        result = consumer.run_once()

        assert result.outcome == "dead_lettered"
        assert redis.llen(config.events_key) == 0
        assert redis.llen(config.processing_key) == 0

        dead = json.loads(cast(str, redis.lpop(config.dead_letter_key)))
        assert dead["payload"] == "not json"
        assert "valid JSON" in dead["error"]

    def test_the_dead_letter_keeps_the_original_payload(
        self, redis: Redis, config: Config, consumer: Consumer
    ) -> None:
        # A dead letter you cannot replay is a log line with extra steps.
        payload = json.dumps({"event": "payment.failed", "message_id": "x"})
        redis.rpush(config.events_key, payload)

        consumer.run_once()

        assert json.loads(cast(str, redis.lpop(config.dead_letter_key)))["payload"] == payload


class TestRetries:
    def test_returns_a_failed_message_for_another_attempt(
        self, redis: Redis, config: Config, monkeypatch: pytest.MonkeyPatch
    ) -> None:
        consumer = Consumer(redis, config)
        monkeypatch.setattr(
            consumer, "_score", lambda event: (_ for _ in ()).throw(RuntimeError("boom"))
        )
        redis.rpush(config.events_key, message())

        result = consumer.run_once()

        assert result.outcome == "retried"
        assert redis.llen(config.events_key) == 1
        assert redis.llen(config.processing_key) == 0

    def test_failure_does_not_mark_the_message_completed(
        self, redis: Redis, config: Config, monkeypatch: pytest.MonkeyPatch
    ) -> None:
        # Holding it would mean a transient failure permanently swallowed the
        # message — the opposite of what idempotency is for.
        consumer = Consumer(redis, config)
        monkeypatch.setattr(
            consumer, "_score", lambda event: (_ for _ in ()).throw(RuntimeError("boom"))
        )
        redis.rpush(config.events_key, message())

        consumer.run_once()

        assert redis.get("risk:completed:msg-1") is None

    def test_dead_letters_once_the_attempts_run_out(
        self, redis: Redis, config: Config, monkeypatch: pytest.MonkeyPatch
    ) -> None:
        consumer = Consumer(redis, config)
        monkeypatch.setattr(
            consumer, "_score", lambda event: (_ for _ in ()).throw(RuntimeError("boom"))
        )
        redis.rpush(config.events_key, message())

        first = consumer.run_once()  # attempt 1 -> retried
        second = consumer.run_once()  # attempt 2 -> max_attempts reached

        assert first.outcome == "retried"
        assert second.outcome == "dead_lettered"
        assert redis.llen(config.events_key) == 0
        assert redis.llen(config.dead_letter_key) == 1

    def test_a_recovered_message_starts_from_a_clean_attempt_count(
        self, redis: Redis, config: Config, consumer: Consumer
    ) -> None:
        redis.rpush(config.events_key, message())
        consumer.run_once()

        assert redis.hget(f"{config.events_key}:attempts", "msg-1") is None


class TestCrashRecovery:
    def test_a_message_orphaned_by_a_dead_worker_is_returned(
        self, redis: Redis, config: Config, consumer: Consumer
    ) -> None:
        # Simulates a worker killed after BLMOVE but before acking.
        redis.rpush(config.processing_key, message())

        # First pass only stamps the marker; the second recovers, because
        # moving messages back too eagerly would duplicate work still in
        # flight on a healthy worker.
        consumer.recover_stale()
        recovered = consumer.recover_stale()

        assert recovered == 1
        assert redis.llen(config.events_key) == 1
        assert redis.llen(config.processing_key) == 0

    def test_recovery_is_a_no_op_when_nothing_is_in_flight(self, consumer: Consumer) -> None:
        assert consumer.recover_stale() == 0

    def test_a_recovered_message_is_then_processed_normally(
        self, redis: Redis, config: Config, consumer: Consumer
    ) -> None:
        redis.rpush(config.processing_key, message())
        consumer.recover_stale()
        consumer.recover_stale()

        assert consumer.run_once().outcome == "scored"
        assert redis.llen(config.scores_key) == 1


class TestDurableCompletion:
    def test_crash_before_scoring_does_not_leave_a_completed_marker(
        self, redis: Redis, config: Config, consumer: Consumer, monkeypatch: pytest.MonkeyPatch
    ) -> None:
        original = consumer._score
        monkeypatch.setattr(consumer, "_score", lambda event: (_ for _ in ()).throw(SystemExit()))
        redis.rpush(config.events_key, message())
        with pytest.raises(SystemExit):
            consumer.run_once()
        monkeypatch.setattr(consumer, "_score", original)
        consumer.recover_stale()
        consumer.recover_stale()
        assert consumer.run_once().outcome == "scored"
        assert redis.llen(config.scores_key) == 1

    def test_publication_failure_leaves_the_message_recoverable(
        self, redis: Redis, config: Config, consumer: Consumer, monkeypatch: pytest.MonkeyPatch
    ) -> None:
        from redis.exceptions import ConnectionError

        original = consumer._publish
        monkeypatch.setattr(
            consumer, "_publish", lambda *args: (_ for _ in ()).throw(ConnectionError())
        )
        redis.rpush(config.events_key, message())
        with pytest.raises(ConnectionError):
            consumer.run_once()
        monkeypatch.setattr(consumer, "_publish", original)
        consumer.recover_stale()
        consumer.recover_stale()
        assert consumer.run_once().outcome == "scored"
        assert redis.llen(config.scores_key) == 1

    def test_concurrent_duplicate_messages_publish_once(self, redis: Redis, config: Config) -> None:
        from concurrent.futures import ThreadPoolExecutor

        redis.rpush(config.events_key, message(), message())
        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(lambda _: Consumer(redis, config).run_once().outcome, range(2)))
        assert sorted(results) == ["duplicate", "scored"]
        assert redis.llen(config.scores_key) == 1
        assert redis.llen(config.processing_key) == 0

    def test_invalid_utf8_reaches_dead_letter_with_original_bytes(self, config: Config) -> None:
        import base64

        client = Redis.from_url(os.environ.get("REDIS_URL", "redis://127.0.0.1:6379/15"))
        client.flushdb()
        raw = b"\xff\xfe"
        client.rpush(config.events_key, raw)
        assert Consumer(client, config).run_once().outcome == "dead_lettered"
        dead = json.loads(cast(bytes, client.lpop(config.dead_letter_key)))
        assert dead["payload_encoding"] == "base64"
        assert base64.b64decode(dead["payload"]) == raw
        assert client.llen(config.processing_key) == 0
        client.flushdb()
