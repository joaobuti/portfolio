"""The wire contract. These cases are the ones PHP could actually send us."""

from __future__ import annotations

import json

import pytest

from risk_worker.envelope import MalformedMessage, parse


def envelope(**data_overrides: object) -> str:
    data: dict[str, object] = {
        "invoice_uuid": "inv-1",
        "customer_uuid": "cus-1",
        "subscription_uuid": "sub-1",
        "amount_minor": 10_001,
        "currency": "BRL",
        "reason": "declined",
        "attempt_number": 1,
        "will_retry": True,
    }
    data.update(data_overrides)

    return json.dumps(
        {
            "message_id": "msg-1",
            "event": "payment.failed",
            "occurred_at": "2026-09-02T09:00:00+00:00",
            "correlation_id": "corr-1",
            "data": data,
        }
    )


class TestHappyPath:
    def test_parses_the_envelope_the_billing_core_publishes(self) -> None:
        event = parse(envelope())

        assert event.message_id == "msg-1"
        assert event.correlation_id == "corr-1"
        assert event.customer_uuid == "cus-1"
        assert event.amount_minor == 10_001
        assert event.reason == "declined"
        assert event.will_retry is True

    def test_accepts_bytes_as_well_as_str(self) -> None:
        assert parse(envelope().encode("utf-8")).message_id == "msg-1"

    def test_a_one_off_invoice_has_no_subscription(self) -> None:
        assert parse(envelope(subscription_uuid=None)).subscription_uuid is None


class TestMoneyCrossingTheBoundary:
    def test_rejects_a_float_amount(self) -> None:
        # A value that arrived as 100.0 already lost a guarantee upstream.
        # Coercing it here would launder that bug into a risk score.
        with pytest.raises(MalformedMessage, match="amount_minor"):
            parse(envelope(amount_minor=100.0))

    def test_rejects_a_numeric_string_amount(self) -> None:
        with pytest.raises(MalformedMessage, match="amount_minor"):
            parse(envelope(amount_minor="10001"))

    def test_rejects_a_boolean_where_an_integer_belongs(self) -> None:
        # bool is a subclass of int in Python; without an explicit guard,
        # `True` would silently parse as an attempt number of 1.
        with pytest.raises(MalformedMessage, match="attempt_number"):
            parse(envelope(attempt_number=True))

    def test_accepts_a_negative_amount(self) -> None:
        # A credit is legitimate — see the proration policy in the core.
        assert parse(envelope(amount_minor=-500)).amount_minor == -500


class TestMalformed:
    def test_rejects_invalid_json(self) -> None:
        with pytest.raises(MalformedMessage, match="valid JSON"):
            parse("not json at all")

    def test_rejects_a_json_array(self) -> None:
        with pytest.raises(MalformedMessage, match="JSON object"):
            parse("[1, 2, 3]")

    def test_rejects_an_unsupported_event(self) -> None:
        payload = json.loads(envelope())
        payload["event"] = "invoice.paid"

        with pytest.raises(MalformedMessage, match="unsupported event"):
            parse(json.dumps(payload))

    def test_rejects_a_message_with_no_id(self) -> None:
        # The idempotency key is not optional. Without it a redelivery cannot
        # be recognised, and at-least-once delivery becomes duplicated work.
        payload = json.loads(envelope())
        del payload["message_id"]

        with pytest.raises(MalformedMessage, match="message_id"):
            parse(json.dumps(payload))

    def test_rejects_a_message_with_no_data(self) -> None:
        payload = json.loads(envelope())
        del payload["data"]

        with pytest.raises(MalformedMessage, match="data object"):
            parse(json.dumps(payload))

    def test_rejects_invalid_utf8(self) -> None:
        with pytest.raises(MalformedMessage, match="UTF-8"):
            parse(b"\xff\xfe not text")

    def test_tolerates_a_missing_correlation_id(self) -> None:
        # Published from a console command rather than an HTTP request, there
        # may be no correlation id. That is not a reason to drop the message.
        payload = json.loads(envelope())
        del payload["correlation_id"]

        assert parse(json.dumps(payload)).correlation_id is None


@pytest.mark.parametrize("value", ["false", 0, 1, None])
def test_requires_a_boolean_retry_flag(value: object) -> None:
    with pytest.raises(MalformedMessage, match="will_retry"):
        parse(envelope(will_retry=value))
