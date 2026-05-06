import contextvars
import hashlib
import importlib
import inspect
import json
import math
import os
import re
import shutil
from pathlib import Path
from typing import Any, Literal

from fastapi import FastAPI
from pydantic import BaseModel, Field

try:
    from langchain_text_splitters import RecursiveCharacterTextSplitter
except Exception:
    RecursiveCharacterTextSplitter = None

try:
    import redis
except Exception:
    redis = None

try:
    import tiktoken
except Exception:
    tiktoken = None

TOKEN_USAGE_CONTEXT: contextvars.ContextVar[dict[str, int] | None] = contextvars.ContextVar(
    "hipporag_token_usage",
    default=None,
)


def initialize_token_usage() -> None:
    TOKEN_USAGE_CONTEXT.set({"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0})


def track_tokens(prompt_tokens: int | None, completion_tokens: int | None) -> None:
    usage = TOKEN_USAGE_CONTEXT.get() or {"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0}
    usage["prompt_tokens"] += max(0, int(prompt_tokens or 0))
    usage["completion_tokens"] += max(0, int(completion_tokens or 0))
    usage["total_tokens"] = usage["prompt_tokens"] + usage["completion_tokens"]
    TOKEN_USAGE_CONTEXT.set(usage)


def current_token_usage() -> dict[str, int]:
    return TOKEN_USAGE_CONTEXT.get() or {"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0}


def read_usage_field(usage: Any, field: str) -> int:
    if usage is None:
        return 0

    if isinstance(usage, dict):
        return int(usage.get(field, 0) or 0)

    return int(getattr(usage, field, 0) or 0)


def extract_usage(response: Any) -> tuple[int, int]:
    usage = response.get("usage") if isinstance(response, dict) else getattr(response, "usage", None)

    if usage is None:
        return 0, 0

    prompt_tokens = read_usage_field(usage, "prompt_tokens")
    completion_tokens = read_usage_field(usage, "completion_tokens")

    if completion_tokens == 0:
        completion_tokens = read_usage_field(usage, "output_tokens")

    return prompt_tokens, completion_tokens


def instrument_openai_calls() -> None:
    targets: list[tuple[str, str]] = [
        ("openai.resources.chat.completions", "Completions"),
        ("openai.resources.completions", "Completions"),
        ("openai.resources.embeddings", "Embeddings"),
    ]

    for module_name, class_name in targets:
        try:
            module = importlib.import_module(module_name)
        except Exception:
            continue

        target = getattr(module, class_name, None)
        if target is None:
            continue

        create_method = getattr(target, "create", None)
        if create_method is None:
            continue

        if getattr(create_method, "__hipporag_wrapped__", False):
            continue

        def create_wrapper(*args: Any, __original=create_method, **kwargs: Any) -> Any:
            response = __original(*args, **kwargs)
            prompt_tokens, completion_tokens = extract_usage(response)
            track_tokens(prompt_tokens, completion_tokens)

            return response

        setattr(create_wrapper, "__hipporag_wrapped__", True)
        setattr(target, "create", create_wrapper)


instrument_openai_calls()

try:
    from hipporag import HippoRAG
except Exception:
    HippoRAG = None


app = FastAPI(title="HippoRAG API")


class SourceInput(BaseModel):
    source_uuid: str
    text: str = Field(min_length=1)
    filename: str | None = None


class IndexRequest(BaseModel):
    work_dir: str
    sources: list[SourceInput] = Field(min_length=1)
    llm_model_name: str = "auto"
    mode: Literal["index", "chunk"] = "index"
    chunk_size: int = Field(default=512, ge=64, le=4096)
    overlap_ratio: float = Field(default=0.12, ge=0.10, le=0.15)


class QueryRequest(BaseModel):
    work_dir: str
    queries: list[str] = Field(min_length=1)
    mode: Literal["rag", "retrieve"] = "rag"
    num_to_retrieve: int = Field(default=5, ge=1, le=50)
    llm_model_name: str = "auto"
    score_threshold: float = Field(default=0.4, ge=0.0, le=1.0)


class DeleteRequest(BaseModel):
    work_dir: str


def hipporag_available() -> bool:
    return HippoRAG is not None


def resolve_llm_base_url() -> str | None:
    return os.getenv("HIPPORAG_LLM_BASE_URL") or os.getenv("FREELLMAPI_INTERNAL_URL") or os.getenv("OPENAI_URL")


def resolve_llm_api_key() -> str | None:
    return os.getenv("HIPPORAG_LLM_API_KEY") or os.getenv("FREELLMAPI_API_KEY") or os.getenv("OPENAI_API_KEY")


def resolve_embedding_base_url() -> str | None:
    return os.getenv("HIPPORAG_EMBEDDING_BASE_URL") or os.getenv("OPENAI_URL") or "https://api.openai.com/v1"


def resolve_embedding_api_key() -> str | None:
    return os.getenv("HIPPORAG_EMBEDDING_API_KEY") or os.getenv("OPENAI_API_KEY")


def resolve_embedding_model_name() -> str:
    return os.getenv("HIPPORAG_EMBEDDING_MODEL", "text-embedding-3-small")


def build_hipporag(work_dir: str, llm_model_name: str) -> Any:
    if HippoRAG is None:
        raise RuntimeError("HippoRAG package is not available")

    llm_base_url = resolve_llm_base_url()
    llm_api_key = resolve_llm_api_key()
    embedding_base_url = resolve_embedding_base_url()
    embedding_api_key = resolve_embedding_api_key()
    embedding_model_name = resolve_embedding_model_name()

    if embedding_api_key:
        os.environ["OPENAI_API_KEY"] = embedding_api_key
    elif llm_api_key:
        os.environ["OPENAI_API_KEY"] = llm_api_key

    constructor_signature = inspect.signature(HippoRAG)
    parameters = constructor_signature.parameters
    kwargs: dict[str, Any] = {
        "save_dir": work_dir,
        "llm_model_name": llm_model_name,
        "embedding_model_name": embedding_model_name,
    }

    optional_parameters = {
        "llm_base_url": llm_base_url,
        "llm_api_key": llm_api_key,
        "embedding_base_url": embedding_base_url,
        "embedding_api_key": embedding_api_key,
    }

    for key, value in optional_parameters.items():
        if value and key in parameters:
            kwargs[key] = value

    return HippoRAG(**kwargs)


def normalize_document(document: Any) -> dict[str, Any]:
    if isinstance(document, dict):
        return {
            "text": str(document.get("text", document.get("content", document.get("document", "")))),
            "score": document.get("score"),
        }

    if isinstance(document, (list, tuple)) and document:
        score = document[1] if len(document) > 1 else None

        return {
            "text": str(document[0]),
            "score": score,
        }

    return {
        "text": str(document),
        "score": None,
    }


def normalize_score(score: Any) -> float | None:
    if score is None:
        return None

    value = float(score)

    return value if math.isfinite(value) else None


def normalize_query_solution(solution: Any) -> list[dict[str, Any]]:
    docs = getattr(solution, "docs", None)
    scores = getattr(solution, "doc_scores", None)

    if docs is None:
        return [normalize_document(document) for document in solution]

    return [
        {
            "text": str(document),
            "score": normalize_score(scores[index]) if scores is not None and index < len(scores) else None,
        }
        for index, document in enumerate(docs)
    ]


def unpack_query_solutions(results: Any) -> Any:
    if isinstance(results, tuple):
        return results[0]

    return results


def normalize_chunk_text(text: str) -> str:
    normalized = re.sub(r"\s+", " ", text.strip())

    return normalized


def chunk_hash(text: str) -> str:
    return hashlib.sha256(normalize_chunk_text(text).encode("utf-8")).hexdigest()


def build_length_function(embedding_model: str):
    if tiktoken is None:
        return lambda text: max(1, math.ceil(len(text) / 4))

    try:
        encoding = tiktoken.encoding_for_model(embedding_model)
    except Exception:
        encoding = tiktoken.get_encoding("cl100k_base")

    return lambda text: len(encoding.encode(text))


def chunk_sources(sources: list[SourceInput], chunk_size: int, overlap_ratio: float) -> list[dict[str, Any]]:
    if RecursiveCharacterTextSplitter is None:
        raise RuntimeError("langchain-text-splitters is required for chunking mode")

    embedding_model = resolve_embedding_model_name()
    chunk_overlap = max(1, int(chunk_size * overlap_ratio))
    chunk_overlap = min(chunk_overlap, chunk_size - 1)

    length_function = build_length_function(embedding_model)
    splitter = RecursiveCharacterTextSplitter(
        chunk_size=chunk_size,
        chunk_overlap=chunk_overlap,
        length_function=length_function,
        separators=["\n\n", "\n", ". ", " ", ""],
    )

    chunks: list[dict[str, Any]] = []
    for source in sources:
        split_chunks = splitter.split_text(source.text)
        for index, split_chunk in enumerate(split_chunks):
            normalized_text = normalize_chunk_text(split_chunk)
            if normalized_text == "":
                continue

            chunks.append(
                {
                    "source_uuid": source.source_uuid,
                    "filename": source.filename,
                    "chunk_index": index,
                    "text": split_chunk,
                    "chunk_hash": chunk_hash(split_chunk),
                    "token_count": int(length_function(split_chunk)),
                }
            )

    return chunks


def graph_info_payload(hipporag: Any) -> dict[str, Any]:
    try:
        graph_info = hipporag.get_graph_info()
    except Exception:
        graph_info = {}

    if not isinstance(graph_info, dict):
        return {}

    num_passage_nodes = int(graph_info.get("num_passage_nodes", 0) or 0)
    num_extracted_triples = int(graph_info.get("num_extracted_triples", 0) or 0)
    facts_per_chunk = (num_extracted_triples / num_passage_nodes) if num_passage_nodes > 0 else 0.0

    return {
        "num_passage_nodes": num_passage_nodes,
        "num_extracted_triples": num_extracted_triples,
        "facts_per_chunk": facts_per_chunk,
    }


class ChunkSourceRegistry:
    def __init__(self) -> None:
        if redis is None:
            raise RuntimeError("redis package is required for source UUID registry")

        redis_url = os.getenv("HIPPORAG_REDIS_URL", "redis://redis:6379/0")
        self.prefix = os.getenv("HIPPORAG_SOURCE_REGISTRY_PREFIX", "hipporag:chunk-source")
        self.client = redis.Redis.from_url(redis_url, decode_responses=True)

    def key(self, work_dir: str, chunk_hash_value: str) -> str:
        work_dir_hash = hashlib.sha256(work_dir.encode("utf-8")).hexdigest()

        return f"{self.prefix}:{work_dir_hash}:{chunk_hash_value}"

    def register(self, work_dir: str, mappings: dict[str, str]) -> None:
        if mappings == {}:
            return

        pipeline = self.client.pipeline(transaction=False)
        for chunk_hash_value, source_uuid in mappings.items():
            pipeline.set(self.key(work_dir, chunk_hash_value), source_uuid)
        pipeline.execute()

    def resolve(self, work_dir: str, chunk_hash_value: str) -> str | None:
        return self.client.get(self.key(work_dir, chunk_hash_value))


def source_registry() -> ChunkSourceRegistry:
    return ChunkSourceRegistry()


def mock_index_file(work_dir: str) -> Path:
    return Path(work_dir).resolve() / "mock_index.json"


def load_mock_index(work_dir: str) -> list[dict[str, Any]]:
    index_file = mock_index_file(work_dir)
    if not index_file.exists():
        return []

    try:
        payload = json.loads(index_file.read_text(encoding="utf-8"))
    except Exception:
        return []

    if not isinstance(payload, list):
        return []

    normalized: list[dict[str, Any]] = []
    for row in payload:
        if not isinstance(row, dict):
            continue

        text = str(row.get("text", ""))
        source_uuid = str(row.get("source_uuid", "")).strip().lower()
        chunk_hash_value = str(row.get("chunk_hash", "")).strip().lower()
        if text == "" or source_uuid == "" or chunk_hash_value == "":
            continue

        normalized.append(
            {
                "text": text,
                "source_uuid": source_uuid,
                "chunk_hash": chunk_hash_value,
            }
        )

    return normalized


def persist_mock_index(work_dir: str, chunks: list[dict[str, Any]]) -> None:
    index_file = mock_index_file(work_dir)
    existing_rows = load_mock_index(work_dir)
    index_by_hash = {str(row["chunk_hash"]): row for row in existing_rows}

    for chunk in chunks:
        text = str(chunk.get("text", ""))
        source_uuid = str(chunk.get("source_uuid", "")).strip().lower()
        chunk_hash_value = str(chunk.get("chunk_hash", "")).strip().lower()
        if text == "" or source_uuid == "" or chunk_hash_value == "":
            continue

        index_by_hash[chunk_hash_value] = {
            "text": text,
            "source_uuid": source_uuid,
            "chunk_hash": chunk_hash_value,
        }

    index_file.parent.mkdir(parents=True, exist_ok=True)
    index_file.write_text(
        json.dumps(list(index_by_hash.values()), ensure_ascii=False, indent=2),
        encoding="utf-8",
    )


def query_terms(query: str) -> set[str]:
    return set(re.findall(r"[a-zA-Z0-9_]+", query.lower()))


def mock_retrieve_documents(
    work_dir: str,
    query: str,
    num_to_retrieve: int,
    score_threshold: float,
) -> list[dict[str, Any]]:
    terms = query_terms(query)
    if terms == set():
        return []

    ranked: list[dict[str, Any]] = []
    for row in load_mock_index(work_dir):
        text = str(row["text"])
        text_terms = query_terms(text)
        if text_terms == set():
            continue

        overlap = len(terms.intersection(text_terms))
        if overlap == 0:
            continue

        score = overlap / max(1, len(terms))
        if score < score_threshold:
            continue

        ranked.append(
            {
                "text": text,
                "score": score,
                "chunk_hash": str(row["chunk_hash"]),
                "source_uuid": str(row["source_uuid"]),
            }
        )

    ranked.sort(key=lambda item: float(item["score"]), reverse=True)

    return ranked[:num_to_retrieve]


@app.get("/health")
def health() -> dict[str, Any]:
    return {"status": "healthy", "hipporag_available": hipporag_available()}


@app.post("/index")
def index(request: IndexRequest) -> dict[str, Any]:
    try:
        initialize_token_usage()
        chunks = chunk_sources(request.sources, request.chunk_size, request.overlap_ratio)
        chunk_preview = [
            {
                "source_uuid": chunk["source_uuid"],
                "filename": chunk["filename"],
                "chunk_index": chunk["chunk_index"],
                "token_count": chunk["token_count"],
                "chunk_hash": chunk["chunk_hash"],
                "text": chunk["text"],
            }
            for chunk in chunks
        ]

        if request.mode == "chunk":
            return {
                "status": "success",
                "mode": "chunk",
                "num_sources": len(request.sources),
                "num_chunks": len(chunks),
                "chunks": chunk_preview,
                "token_usage": current_token_usage(),
            }

        Path(request.work_dir).mkdir(parents=True, exist_ok=True)
        if HippoRAG is None:
            persist_mock_index(request.work_dir, chunks)

            return {
                "status": "success",
                "mode": "index",
                "num_sources": len(request.sources),
                "num_chunks": len(chunks),
                "graph_info": {
                    "num_passage_nodes": len(chunks),
                    "num_extracted_triples": 0,
                    "facts_per_chunk": 0.0,
                },
                "token_usage": current_token_usage(),
            }

        hipporag = build_hipporag(request.work_dir, request.llm_model_name)
        hipporag.index(docs=[chunk["text"] for chunk in chunks])

        registry = source_registry()
        registry.register(
            request.work_dir,
            {
                chunk["chunk_hash"]: chunk["source_uuid"]
                for chunk in chunks
            },
        )

        return {
            "status": "success",
            "mode": "index",
            "num_sources": len(request.sources),
            "num_chunks": len(chunks),
            "graph_info": graph_info_payload(hipporag),
            "token_usage": current_token_usage(),
        }
    except Exception as exception:
        return {"status": "error", "detail": str(exception)}


@app.post("/query")
def query(request: QueryRequest) -> dict[str, Any]:
    try:
        initialize_token_usage()
        if HippoRAG is None:
            return {
                "status": "success",
                "results": [
                    {
                        "query": query_text,
                        "documents": mock_retrieve_documents(
                            request.work_dir,
                            query_text,
                            request.num_to_retrieve,
                            request.score_threshold,
                        ),
                    }
                    for query_text in request.queries
                ],
                "mode": "retrieve",
                "score_threshold": request.score_threshold,
                "token_usage": current_token_usage(),
            }

        hipporag = build_hipporag(request.work_dir, request.llm_model_name)

        try:
            retrieval_results = hipporag.retrieve(queries=request.queries, num_to_retrieve=request.num_to_retrieve)
        except AssertionError:
            retrieval_results = hipporag.retrieve_dpr(queries=request.queries, num_to_retrieve=request.num_to_retrieve)

        retrieval_results = unpack_query_solutions(retrieval_results)
        registry = source_registry()
        results: list[dict[str, Any]] = []

        for query_text, solution in zip(request.queries, retrieval_results):
            normalized_documents = normalize_query_solution(solution)
            filtered_documents: list[dict[str, Any]] = []

            for document in normalized_documents:
                score = normalize_score(document.get("score"))
                if score is None or score < request.score_threshold:
                    continue

                text = str(document.get("text", ""))
                resolved_chunk_hash = chunk_hash(text)
                filtered_documents.append(
                    {
                        "text": text,
                        "score": score,
                        "chunk_hash": resolved_chunk_hash,
                        "source_uuid": registry.resolve(request.work_dir, resolved_chunk_hash),
                    }
                )

            results.append(
                {
                    "query": query_text,
                    "documents": filtered_documents,
                }
            )

        return {
            "status": "success",
            "results": results,
            "mode": "retrieve",
            "score_threshold": request.score_threshold,
            "token_usage": current_token_usage(),
        }
    except Exception as exception:
        return {"status": "error", "detail": str(exception)}


@app.post("/delete")
def delete(request: DeleteRequest) -> dict[str, Any]:
    try:
        data_root = Path(os.getenv("HIPPORAG_DATA_ROOT", "/app/data")).resolve()
        target = Path(request.work_dir).resolve()

        if not target.is_relative_to(data_root):
            return {"status": "error", "detail": "Refusing to delete outside HippoRAG data root"}

        shutil.rmtree(target, ignore_errors=True)

        return {"status": "success", "deleted": True}
    except Exception as exception:
        return {"status": "error", "detail": str(exception)}
