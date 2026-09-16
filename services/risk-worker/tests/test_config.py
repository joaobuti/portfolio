"""Configuration.

The first test here exists because of a real bug: with ``slots=True`` on a
dataclass, ``Config.redis_url`` returns a slot descriptor rather than the
default string. Every test that built a Config explicitly passed happily while
``from_env()`` — the only path production uses — was quietly broken.
"""

from __future__ import annotations

import pytest

from risk_worker.config import DEFAULT_EVENTS_KEY, DEFAULT_REDIS_URL, Config


class TestDefaults:
    def test_from_env_returns_real_strings_with_no_environment_set(
        self, monkeypatch: pytest.MonkeyPatch
    ) -> None:
        for name in (
            "REDIS_URL",
            "RISK_EVENTS_KEY",
            "RISK_SCORES_KEY",
            "RISK_BLOCK_SECONDS",
        ):
            monkeypatch.delenv(name, raising=False)

        config = Config.from_env()

        assert config.redis_url == DEFAULT_REDIS_URL
        assert isinstance(config.redis_url, str)
        assert config.events_key == DEFAULT_EVENTS_KEY
        assert isinstance(config.block_seconds, int)

    def test_the_worker_starts_with_no_configuration_at_all(
        self, monkeypatch: pytest.MonkeyPatch
    ) -> None:
        # The property the compose stack relies on.
        monkeypatch.delenv("REDIS_URL", raising=False)

        assert Config.from_env().redis_url.startswith("redis://")


class TestOverrides:
    def test_reads_the_environment(self, monkeypatch: pytest.MonkeyPatch) -> None:
        monkeypatch.setenv("REDIS_URL", "redis://elsewhere:6379/3")
        monkeypatch.setenv("RISK_EVENTS_KEY", "custom:events")
        monkeypatch.setenv("RISK_MAX_ATTEMPTS", "7")

        config = Config.from_env()

        assert config.redis_url == "redis://elsewhere:6379/3"
        assert config.events_key == "custom:events"
        assert config.max_attempts == 7

    def test_an_empty_variable_falls_back_to_the_default(
        self, monkeypatch: pytest.MonkeyPatch
    ) -> None:
        # An unset variable in a compose file often arrives as "".
        monkeypatch.setenv("RISK_MAX_ATTEMPTS", "")

        assert Config.from_env().max_attempts == 3

    def test_a_non_numeric_value_fails_loudly_at_startup(
        self, monkeypatch: pytest.MonkeyPatch
    ) -> None:
        # Better than silently running with a default nobody chose.
        monkeypatch.setenv("RISK_MAX_ATTEMPTS", "three")

        with pytest.raises(ValueError, match="RISK_MAX_ATTEMPTS"):
            Config.from_env()
