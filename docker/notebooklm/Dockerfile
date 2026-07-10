FROM python:3.11-slim

ENV PIPX_HOME=/opt/pipx
ENV PIPX_BIN_DIR=/usr/local/bin
ENV PATH=/usr/local/bin:/opt/pipx/bin:${PATH}
ENV PIP_ROOT_USER_ACTION=ignore

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ca-certificates \
        git \
    && rm -rf /var/lib/apt/lists/*

RUN python -m pip install --no-cache-dir \
    fastapi \
    uvicorn \
    httpx \
    pydantic \
    && mkdir -p /root/.notebooklm /workspace /app

RUN python -m pip install --no-cache-dir \
    "notebooklm-py"

WORKDIR /app

COPY docker/notebooklm/main.py  /app/main.py
COPY docker/notebooklm/pool.py  /app/pool.py
COPY docker/notebooklm/models.py /app/models.py
COPY docker/notebooklm/api     /app/api

HEALTHCHECK --interval=120s --timeout=5s --retries=3 \
    CMD curl -f http://localhost:8000/health || exit 1

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]
