import math
import os
import shutil
from pathlib import Path
from typing import Any, Literal

from fastapi import FastAPI
from pydantic import BaseModel, Field

try:
    from hipporag import HippoRAG
except Exception:
    HippoRAG = None


app = FastAPI(title="HippoRAG API")


class IndexRequest(BaseModel):
    work_dir: str
    documents: list[str] = Field(min_length=1)
    llm_model: str
    embedding_model: str
    llm_base_url: str | None = None
    embedding_base_url: str | None = None
    llm_api_key: str | None = None


class QueryRequest(BaseModel):
    work_dir: str
    queries: list[str] = Field(min_length=1)
    mode: Literal["rag", "retrieve"] = "rag"
    num_to_retrieve: int = 5
    llm_model: str
    embedding_model: str
    llm_base_url: str | None = None
    embedding_base_url: str | None = None
    llm_api_key: str | None = None


class DeleteRequest(BaseModel):
    work_dir: str


def hipporag_available() -> bool:
    return HippoRAG is not None


def build_hipporag(
    work_dir: str,
    llm_model: str,
    embedding_model: str,
    llm_base_url: str | None,
    embedding_base_url: str | None,
    llm_api_key: str | None,
) -> Any:
    if HippoRAG is None:
        raise RuntimeError("HippoRAG package is not available")

    kwargs = {
        "save_dir": work_dir,
        "llm_model_name": llm_model,
        "embedding_model_name": embedding_model,
    }

    if llm_base_url:
        kwargs["llm_base_url"] = llm_base_url

    if embedding_base_url:
        kwargs["embedding_base_url"] = embedding_base_url

    resolved_llm_api_key = llm_api_key or os.getenv("HIPPORAG_LLM_API_KEY")
    if resolved_llm_api_key:
        os.environ["OPENAI_API_KEY"] = resolved_llm_api_key

    return HippoRAG(**kwargs)


def normalize_rag_result(query: str, result: Any) -> dict[str, Any]:
    if isinstance(result, dict):
        return {
            "question": str(result.get("question", query)),
            "answer": str(result.get("answer", result.get("response", ""))),
            "sources": result.get("sources", result.get("documents", [])) or [],
        }

    if hasattr(result, "docs"):
        return {
            "question": str(getattr(result, "question", query)),
            "answer": str(getattr(result, "answer", "") or ""),
            "sources": normalize_query_solution(result),
        }

    return {
        "question": query,
        "answer": str(result),
        "sources": [],
    }


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


@app.get("/health")
def health() -> dict[str, Any]:
    return {"status": "healthy", "hipporag_available": hipporag_available()}


@app.post("/index")
def index(request: IndexRequest) -> dict[str, Any]:
    try:
        Path(request.work_dir).mkdir(parents=True, exist_ok=True)
        hipporag = build_hipporag(
            request.work_dir,
            request.llm_model,
            request.embedding_model,
            request.llm_base_url,
            request.embedding_base_url,
            request.llm_api_key,
        )
        hipporag.index(docs=request.documents)

        return {"status": "success", "num_documents": len(request.documents)}
    except Exception as exception:
        return {"status": "error", "detail": str(exception)}


@app.post("/query")
def query(request: QueryRequest) -> dict[str, Any]:
    try:
        hipporag = build_hipporag(
            request.work_dir,
            request.llm_model,
            request.embedding_model,
            request.llm_base_url,
            request.embedding_base_url,
            request.llm_api_key,
        )

        if request.mode == "retrieve":
            try:
                retrieval_results = hipporag.retrieve(queries=request.queries, num_to_retrieve=request.num_to_retrieve)
            except AssertionError:
                retrieval_results = hipporag.retrieve_dpr(queries=request.queries, num_to_retrieve=request.num_to_retrieve)

            retrieval_results = unpack_query_solutions(retrieval_results)
            results = [
                {
                    "query": query_text,
                    "documents": normalize_query_solution(solution),
                }
                for query_text, solution in zip(request.queries, retrieval_results)
            ]

            return {"status": "success", "results": results}

        try:
            rag_results = hipporag.rag_qa(queries=request.queries)
        except AssertionError:
            rag_results = hipporag.rag_qa_dpr(queries=request.queries)

        rag_results = unpack_query_solutions(rag_results)
        results = [
            normalize_rag_result(query_text, result)
            for query_text, result in zip(request.queries, rag_results)
        ]

        return {"status": "success", "results": results}
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
