"""The wire contract, parsed defensively.

Everything here crosses a language boundary from PHP, so nothing is trusted:
a field that should be an int may arrive as a string, a float, or not at all.
Parsing returns an error rather than raising for malformed input, because a bad
message must go to the dead-letter list — not crash the worker and stall every
message behind it.
"""

from __future__ import annotations

import json
from dataclasses import dataclass


class MalformedMessage(Exception):
    """The payload is not something this worker can act on. Never retried."""


@dataclass(frozen=True, slots=True)
class PaymentFailedEvent:
    message_id: str
    correlation_id: str | None
    occurred_at: str | None
    invoice_uuid: str
    customer_uuid: str
    subscription_uuid: str | None
    amount_minor: int
    currency: str
    reason: str
    attempt_number: int
    will_retry: bool


def parse(raw: str | bytes) -> PaymentFailedEvent:
    """Parse one message off the list.

    :raises MalformedMessage: for anything that cannot be acted on.
    """
    if isinstance(raw, bytes):
        try:
            raw = raw.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise MalformedMessage(f"payload is not valid UTF-8: {exc}") from exc

    try:
        payload = json.loads(raw)
    except (ValueError, RecursionError) as exc:
        raise MalformedMessage(f"payload is not valid JSON: {exc}") from exc

    if not isinstance(payload, dict):
        raise MalformedMessage("payload is not a JSON object")

    event = payload.get("event")
    if event != "payment.failed":
        raise MalformedMessage(f"unsupported event [{event!r}]")

    message_id = _require_str(payload, "message_id")
    data = payload.get("data")
    if not isinstance(data, dict):
        raise MalformedMessage("payload has no data object")

    return PaymentFailedEvent(
        message_id=message_id,
        correlation_id=_optional_str(payload, "correlation_id"),
        occurred_at=_optional_str(payload, "occurred_at"),
        invoice_uuid=_require_str(data, "invoice_uuid"),
        customer_uuid=_require_str(data, "customer_uuid"),
        subscription_uuid=_optional_str(data, "subscription_uuid"),
        amount_minor=_require_int(data, "amount_minor"),
        currency=_require_str(data, "currency"),
        reason=_require_str(data, "reason"),
        attempt_number=_require_int(data, "attempt_number"),
        will_retry=_require_bool(data, "will_retry"),
    )


def _require_str(source: dict[str, object], key: str) -> str:
    value = source.get(key)
    if not isinstance(value, str) or not value:
        raise MalformedMessage(f"[{key}] must be a non-empty string")
    return value


def _optional_str(source: dict[str, object], key: str) -> str | None:
    value = source.get(key)
    return value if isinstance(value, str) and value else None


def _require_int(source: dict[str, object], key: str) -> int:
    value = source.get(key)
    # Explicitly not accepting a float or a numeric string. Money crosses this
    # boundary; a value that arrived as 100.0 has already lost a guarantee
    # somewhere upstream, and coercing it here would launder that bug.
    if isinstance(value, bool) or not isinstance(value, int):
        raise MalformedMessage(f"[{key}] must be an integer")
    return value


def _require_bool(source: dict[str, object], key: str) -> bool:
    value = source.get(key)
    if not isinstance(value, bool):
        raise MalformedMessage(f"[{key}] must be a boolean")
    return value
