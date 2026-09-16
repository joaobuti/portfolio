"""Delinquency risk scoring.

The counterpart to the billing core's ``ProrationCalculator``: pure functions,
no clock, no I/O, no Redis. Everything this module needs arrives as an
argument, which is why the whole scoring surface is tested in milliseconds
without starting anything.

The model is deliberately simple and explainable. A score a support agent
cannot account for is a score nobody trusts, so every contribution is named and
returned alongside the number.
"""

from __future__ import annotations

from dataclasses import dataclass
from enum import Enum
from typing import Final

# Mirrors App\Enums\PaymentFailureReason in the billing core. Kept as plain
# strings rather than imported from anywhere: the wire format is the contract,
# and an unknown value must be handled rather than crash the worker.
REASON_DECLINED: Final = "declined"
REASON_INSUFFICIENT_FUNDS: Final = "insufficient_funds"
REASON_EXPIRED_CARD: Final = "expired_card"
REASON_NETWORK_ERROR: Final = "network_error"
REASON_INVALID_REQUEST: Final = "invalid_request"

# What each failure says about the customer's likelihood of not paying.
#
# The ordering is the interesting part. An expired card scores highest not
# because it is the worst failure but because it is the one that *cannot*
# resolve itself — no number of retries fixes it, only the customer acting.
# Insufficient funds scores lower than a flat decline because it very often
# clears on payday.
_BASE_BY_REASON: Final[dict[str, int]] = {
    REASON_EXPIRED_CARD: 60,
    REASON_DECLINED: 45,
    REASON_INSUFFICIENT_FUNDS: 35,
    # A network error says nothing at all about the customer. Scoring it like a
    # decline would let an outage on our side quietly mark good payers as risky.
    REASON_NETWORK_ERROR: 5,
    # Our own bug. Never the customer's risk.
    REASON_INVALID_REQUEST: 0,
}

# An unrecognised reason from a future version of the producer. Scored
# conservatively rather than dropped: the failure happened, we just do not know
# what kind. Dropping it would silently under-report risk.
_BASE_UNKNOWN: Final = 40

_PER_EXTRA_ATTEMPT: Final = 12
_MAX_ATTEMPT_BONUS: Final = 36

# We stopped retrying, which means the schedule was exhausted or the failure
# was unfixable. Either way it is a stronger signal than a single decline.
_GAVE_UP_BONUS: Final = 10

# Large balances are worth marginally more attention, but only marginally —
# amount is a business impact signal, not a behavioural one, and letting it
# dominate would rank one expensive customer above ten defaulting ones.
_LARGE_AMOUNT_MINOR: Final = 50_000
_LARGE_AMOUNT_BONUS: Final = 5


class Band(str, Enum):
    """Coarse bucket for routing. Thresholds live here, once."""

    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"

    @staticmethod
    def for_score(score: int) -> Band:
        if score < 30:
            return Band.LOW
        if score < 60:
            return Band.MEDIUM
        return Band.HIGH


@dataclass(frozen=True, slots=True)
class Factor:
    """One named contribution to a score."""

    code: str
    points: int


@dataclass(frozen=True, slots=True)
class RiskAssessment:
    score: int
    band: Band
    factors: tuple[Factor, ...]

    def factor_points(self) -> int:
        """What the factors add up to, before clamping."""
        return sum(factor.points for factor in self.factors)


def score_payment_failure(
    *,
    reason: str,
    attempt_number: int,
    will_retry: bool,
    amount_minor: int,
) -> RiskAssessment:
    """Score one failed payment.

    Keyword-only on purpose: four arguments of which two are ints and one a
    bool is exactly the signature that gets called with the wrong order a year
    from now.
    """
    factors: list[Factor] = []

    base = _BASE_BY_REASON.get(reason, _BASE_UNKNOWN)
    factors.append(Factor(f"reason:{reason}", base))

    # Our own bug is never the customer's risk, and nothing else about the
    # attempt changes that. Returning early keeps that guarantee in one place
    # rather than relying on every later term happening to be zero.
    if reason == REASON_INVALID_REQUEST:
        return RiskAssessment(score=0, band=Band.LOW, factors=tuple(factors))

    extra_attempts = max(0, attempt_number - 1)
    if extra_attempts:
        points = min(extra_attempts * _PER_EXTRA_ATTEMPT, _MAX_ATTEMPT_BONUS)
        factors.append(Factor("repeat_attempts", points))

    if not will_retry:
        factors.append(Factor("no_further_retries", _GAVE_UP_BONUS))

    if abs(amount_minor) >= _LARGE_AMOUNT_MINOR:
        factors.append(Factor("large_balance", _LARGE_AMOUNT_BONUS))

    total = sum(factor.points for factor in factors)
    score = max(0, min(100, total))

    return RiskAssessment(score=score, band=Band.for_score(score), factors=tuple(factors))
