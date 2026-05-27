FROM mcr.microsoft.com/playwright/python:v1.60.0-jammy

ENV PIPX_HOME=/opt/pipx
ENV PIPX_BIN_DIR=/usr/local/bin
ENV PATH=/usr/local/bin:/opt/pipx/bin:${PATH}
ENV PIP_ROOT_USER_ACTION=ignore

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3.10-venv \
    && rm -rf /var/lib/apt/lists/*

RUN python -m pip install --no-cache-dir pipx \
    && pipx install "notebooklm-py[browser]" \
    && mkdir -p /root/.notebooklm /workspace

WORKDIR /workspace

CMD ["tail", "-f", "/dev/null"]
