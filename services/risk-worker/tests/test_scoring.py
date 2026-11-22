"""Scoring is pure, so these run without Redis, a clock, or a container."""

from __future__ import annotations

import pytest

from risk_worker.scoring import (
    REASON_DECLINED,
    REASON_EXPIRED_CARD,
    REASON_INSUFFICIENT_FUNDS,
    REASON_INVALID_REQUEST,
    REASON_NETWORK_ERROR,
    Band,
    score_payment_failure,
)


def score(**overrides: object) -> int:
    defaults: dict[str, object] = {
        "reason": REASON_DECLINED,
        "attempt_number": 1,
        "will_retry": True,
        "amount_minor": 10_000,
    }
    defaults.update(overrides)
    return score_payment_failure(**defaults).score  # type: ignore[arg-type]


class TestReasonOrdering:
    def test_an_unfixable_failure_outranks_a_recoverable_one(self) -> None:
        # An expired card scores highest not because it is the worst failure
        # but because it is the one no retry can fix.
        assert score(reason=REASON_EXPIRED_CARD) > score(reason=REASON_DECLINED)

    def test_insufficient_funds_scores_below_a_flat_decline(self) -> None:
        # It very often clears on payday.
        assert score(reason=REASON_INSUFFICIENT_FUNDS) < score(reason=REASON_DECLINED)

    def test_a_network_error_barely_registers(self) -> None:
        # It says nothing about the customer. Scoring it like a decline would
        # let an outage on our side mark good payers as risky.
        assert score(reason=REASON_NETWORK_ERROR) < 30
        assert (
            score_payment_failure(
                reason=REASON_NETWORK_ERROR,
                attempt_number=1,
                will_retry=True,
                amount_minor=10_000,
            ).band
            is Band.LOW
        )


class TestOurOwnBugs:
    def test_an_invalid_request_never_scores_the_customer(self) -> None:
        # This is our bug. No combination of the other inputs may change it.
        assessment = score_payment_failure(
            reason=REASON_INVALID_REQUEST,
            attempt_number=9,
            will_retry=False,
            amount_minor=10_000_000,
        )

        assert assessment.score == 0
        assert assessment.band is Band.LOW


class TestEscalation:
    def test_repeated_attempts_raise_the_score(self) -> None:
        assert score(attempt_number=3) > score(attempt_number=1)

    def test_the_attempt_bonus_is_capped(self) -> None:
        # Otherwise a long dunning cycle would pin every customer at 100 and
        # the score would stop distinguishing anything.
        assert score(attempt_number=8) == score(attempt_number=20)

    def test_giving_up_raises_the_score(self) -> None:
        assert score(will_retry=False) > score(will_retry=True)

    def test_a_large_balance_nudges_but_does_not_dominate(self) -> None:
        small = score(amount_minor=1_000)
        large = score(amount_minor=500_000)

        assert large > small
        # Amount is business impact, not behaviour. Letting it dominate would
        # rank one expensive customer above ten defaulting ones.
        assert large - small <= 10


class TestBounds:
    @pytest.mark.parametrize(
        "reason",
        [
            REASON_DECLINED,
            REASON_INSUFFICIENT_FUNDS,
            REASON_EXPIRED_CARD,
            REASON_NETWORK_ERROR,
            REASON_INVALID_REQUEST,
            "something_a_future_producer_sends",
        ],
    )
    def test_the_score_always_lands_in_range(self, reason: str) -> None:
        for attempts in (1, 5, 50):
            for will_retry in (True, False):
                assessment = score_payment_failure(
                    reason=reason,
                    attempt_number=attempts,
                    will_retry=will_retry,
                    amount_minor=10_000_000,
                )
                assert 0 <= assessment.score <= 100
                assert assessment.band is Band.for_score(assessment.score)

    def test_an_unknown_reason_is_scored_conservatively_not_dropped(self) -> None:
        # The failure happened; we just do not know what kind. Treating it as
        # zero would silently under-report risk when the producer adds a case.
        assert score(reason="brand_new_decline_code") > 0


class TestExplainability:
    def test_every_contribution_is_named(self) -> None:
        # A score a support agent cannot account for is a score nobody trusts.
        assessment = score_payment_failure(
            reason=REASON_EXPIRED_CARD,
            attempt_number=3,
            will_retry=False,
            amount_minor=500_000,
        )

        codes = [factor.code for factor in assessment.factors]

        assert codes == [
            "reason:expired_card",
            "repeat_attempts",
            "no_further_retries",
            "large_balance",
        ]

    def test_the_factors_reconcile_with_the_score(self) -> None:
        assessment = score_payment_failure(
            reason=REASON_DECLINED,
            attempt_number=2,
            will_retry=True,
            amount_minor=1_000,
        )

        assert assessment.factor_points() == assessment.score


class TestBands:
    @pytest.mark.parametrize(
        ("value", "expected"),
        [
            (0, Band.LOW),
            (29, Band.LOW),
            (30, Band.MEDIUM),
            (59, Band.MEDIUM),
            (60, Band.HIGH),
            (100, Band.HIGH),
        ],
    )
    def test_thresholds(self, value: int, expected: Band) -> None:
        assert Band.for_score(value) is expected
