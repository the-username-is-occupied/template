# Cloud-only HipporAG (OpenAI-compatible LLM + embeddings, openie_mode=online by default).
# Upstream hipporag on PyPI depends on vLLM/transformers-heavy stacks via eager imports; we install with
# `--no-deps`, trim optional stacks, lazy-import optional backends, and keep Torch CPU-only for retrieval math.
FROM python:3.10-slim-bookworm

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

# Safety net: refuse accidental HF weight downloads when a wrong embedding_model_name is configured.
ENV HF_HUB_OFFLINE=1 \
    HF_HUB_DISABLE_TELEMETRY=1 \
    TRANSFORMERS_OFFLINE=1 \
    TOKENIZERS_PARALLELISM=false \
    HF_HOME=/app/data/huggingface

RUN mkdir -p /app/data/huggingface

COPY requirements.txt .

RUN pip install --no-cache-dir --upgrade pip setuptools wheel \
    && pip install --no-cache-dir -r requirements.txt

# HipporAG KNN retrieval uses Torch CPU tensors; CUDA drivers are unnecessary.
RUN pip install --no-cache-dir torch==2.5.1 --index-url https://download.pytorch.org/whl/cpu

ARG HIPPORAG_VERSION=2.0.0a4

RUN pip install --no-cache-dir hipporag==${HIPPORAG_VERSION} --no-deps

COPY scripts/apply_hipporag_cloud_patches.py /tmp/apply_hipporag_cloud_patches.py
RUN python /tmp/apply_hipporag_cloud_patches.py && rm /tmp/apply_hipporag_cloud_patches.py

COPY main.py .

RUN python - <<'PY'
from hipporag import HippoRAG as HippoRAG  # noqa: F401

print("hipporag import smoke ok")
PY

EXPOSE 8000

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
