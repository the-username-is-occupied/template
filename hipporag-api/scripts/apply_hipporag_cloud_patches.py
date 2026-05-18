#!/usr/bin/env python3
"""Patch upstream hipporag (PyPI) so the container avoids eager imports for vLLM / Bedrock / local HF embeddings.

Upstream HippoRAG (2.0.0a4) imports optional backends at package import time, which pulls vLLM (+ CUDA stack)
and gritlm even when only OpenAI-compatible cloud LLM/embeddings are used (openie_mode=online by default).
"""

from __future__ import annotations

import pathlib

import hipporag


def _patch_file(path: pathlib.Path, replacements: list[tuple[str, str]]) -> None:
    text = path.read_text(encoding="utf-8")
    original_text = text

    for old, new in replacements:
        if old not in text:
            raise SystemExit(f"hpp patch: expected snippet not found in {path}: {old!r}")
        text = text.replace(old, new, 1)

    if text == original_text:
        raise SystemExit(f"hpp patch: no changes written for {path}")

    path.write_text(text, encoding="utf-8")


def apply_patches(package_root: pathlib.Path) -> None:
    hipporag_py = package_root / "HippoRAG.py"
    _patch_file(
        hipporag_py,
        [
            (
                "from transformers import HfArgumentParser\n",
                "",
            ),
            (
                "from .information_extraction.openie_vllm_offline import VLLMOfflineOpenIE\n",
                "",
            ),
            (
                """        elif self.global_config.openie_mode == 'offline':
            self.openie = VLLMOfflineOpenIE(self.global_config)
""",
                """        elif self.global_config.openie_mode == 'offline':
            # Lazy-import vLLM offline OpenIE only when explicitly requested via BaseConfig.openie_mode.
            from .information_extraction.openie_vllm_offline import VLLMOfflineOpenIE

            self.openie = VLLMOfflineOpenIE(self.global_config)
""",
            ),
        ],
    )

    llm_init = package_root / "llm" / "__init__.py"
    _patch_file(
        llm_init,
        [
            (
                """from ..utils.logging_utils import get_logger
from ..utils.config_utils import BaseConfig

from .openai_gpt import CacheOpenAI
from .base import BaseLLM
from .bedrock_llm import BedrockLLM


logger = get_logger(__name__)


def _get_llm_class(config: BaseConfig):
    if config.llm_base_url is not None and 'localhost' in config.llm_base_url and os.getenv('OPENAI_API_KEY') is None:
        os.environ['OPENAI_API_KEY'] = 'sk-'

    if config.llm_name.startswith('bedrock'):
        return BedrockLLM(config)
    
    return CacheOpenAI.from_experiment_config(config)
""",
                """from ..utils.logging_utils import get_logger
from ..utils.config_utils import BaseConfig

from .openai_gpt import CacheOpenAI
from .base import BaseLLM


logger = get_logger(__name__)


def _get_llm_class(config: BaseConfig):
    if config.llm_base_url is not None and 'localhost' in config.llm_base_url and os.getenv('OPENAI_API_KEY') is None:
        os.environ['OPENAI_API_KEY'] = 'sk-'

    if config.llm_name.startswith('bedrock'):
        # Lazy-import to avoid pulling litellm/boto stacks unless Bedrock paths are selected.
        from .bedrock_llm import BedrockLLM

        return BedrockLLM(config)

    return CacheOpenAI.from_experiment_config(config)
""",
            ),
        ],
    )

    embedding_init = package_root / "embedding_model" / "__init__.py"
    embedding_init.write_text(
        '''from ..utils.logging_utils import get_logger

logger = get_logger(__name__)


def _get_embedding_model_class(embedding_model_name: str = "nvidia/NV-Embed-v2"):
    """
    Return the embedding backend class for the configured model id.

    Note: Imports are intentionally lazy so cloud-only installs can avoid gritlm / HF weights / Contriever stacks.
    """
    if "GritLM" in embedding_model_name:
        from .GritLM import GritLMEmbeddingModel

        return GritLMEmbeddingModel

    elif "NV-Embed-v2" in embedding_model_name:
        from .NVEmbedV2 import NVEmbedV2EmbeddingModel

        return NVEmbedV2EmbeddingModel

    elif "contriever" in embedding_model_name:
        from .Contriever import ContrieverModel

        return ContrieverModel

    elif "text-embedding" in embedding_model_name:
        from .OpenAI import OpenAIEmbeddingModel

        return OpenAIEmbeddingModel

    elif "cohere" in embedding_model_name:
        from .Cohere import CohereEmbeddingModel

        return CohereEmbeddingModel

    raise AssertionError(f"Unknown embedding model name: {embedding_model_name}")

''',
        encoding="utf-8",
    )

    openai_emb = package_root / "embedding_model" / "OpenAI.py"
    original_openai_emb = openai_emb.read_text(encoding="utf-8")
    patched_openai_emb = original_openai_emb.replace(
        "import numpy as np\nimport torch\nfrom tqdm import tqdm\nfrom transformers import AutoModel\n",
        "import numpy as np\nfrom tqdm import tqdm\n",
        1,
    )

    patched_openai_emb = patched_openai_emb.replace(
        """        if isinstance(results, torch.Tensor):
            results = results.cpu()
            results = results.numpy()
""",
        "",
        1,
    )

    if patched_openai_emb == original_openai_emb:
        raise SystemExit(f"hpp patch: failed to rewrite {openai_emb}")

    openai_emb.write_text(patched_openai_emb, encoding="utf-8")


def main() -> None:
    package_root = pathlib.Path(hipporag.__file__).resolve().parent
    apply_patches(package_root)


if __name__ == "__main__":
    main()
