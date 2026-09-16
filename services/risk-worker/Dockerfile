# Mirrors the other two services: multi-stage, non-root, no build tooling in
# the production image.

FROM python:3.12-alpine AS base

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

# ----------------------------------------------------------- development

FROM base AS development

COPY pyproject.toml ./
COPY src ./src
COPY tests ./tests

RUN pip install -q -e ".[dev]"

CMD ["python", "-m", "risk_worker"]

# ------------------------------------------------------------ production

FROM base AS production

# Dependencies first, so a source-only change does not reinstall redis.
COPY pyproject.toml ./
COPY src ./src

RUN pip install -q . && \
    adduser -D -H worker

USER worker

# No healthcheck: this process serves no port. Whether it is doing anything is
# a question about queue depth, which belongs to monitoring rather than to a
# liveness probe that can only ever answer "the process exists".
CMD ["python", "-m", "risk_worker"]
