"""ChatAPI endpoints — wraps notebooklm-py ChatAPI."""
from fastapi import APIRouter, Request
from notebooklm import ChatGoal, ChatResponseLength
from models import AskRequest, AskResponse, AskResult, ChatReference, ConfigureChatRequest, NextStepSuggestion
from pool import get_client
from api.utils import elapsed_ms, pick

router = APIRouter()


def _map_reference(ref) -> ChatReference:
    return ChatReference(
        source_id=pick(ref, "source_id"),
        citation_number=pick(ref, "citation_number"),
        cited_text=pick(ref, "cited_text"),
        start_char=pick(ref, "start_char"),
        end_char=pick(ref, "end_char"),
        chunk_id=pick(ref, "chunk_id"),
    )

def _map_suggestions(ref) -> NextStepSuggestion:
    return NextStepSuggestion(
        question=pick(ref, "question"),
        type_code=pick(ref, "type_code")
    )


@router.post("/notebooks/ask", response_model=AskResponse)
async def ask_question(request: Request, account_id: str, body: AskRequest):
    """Ask a question in a notebook, clearing any prior conversation first."""
    client = get_client(account_id)

    # last_conv_id = await client.chat.get_conversation_id(body.notebook_id)
    # if last_conv_id:
    #     await client.chat.delete_conversation(body.notebook_id, last_conv_id)

    result = await client.chat.ask(
        notebook_id=body.notebook_id,
        question=body.question,
        source_ids=body.source_ids,
        conversation_id=body.conversation_id,
    )
    
    ask_result = AskResult(
        answer=pick(result, "answer", ""),
        conversation_id=pick(result, "conversation_id", ""),
        turn_number=pick(result, "turn_number", 0),
        is_follow_up=pick(result, "is_follow_up", False),
        references=[_map_reference(r) for r in (pick(result, "references") or [])],
        next_steps=[_map_suggestions(s) for s in (pick(result, "next_steps") or [])],
    )
    
    return AskResponse(response_time_ms=elapsed_ms(request), result=ask_result)


@router.get("/notebooks/{notebook_id}/chat/history")
async def get_chat_history(request: Request, account_id: str, notebook_id: str):
    """Get the chat history for a notebook."""
    client = get_client(account_id)
    history = await client.chat.get_history(notebook_id)
    return {"response_time_ms": elapsed_ms(request), "history": history}


@router.delete("/notebooks/{notebook_id}/chat/conversation")
async def delete_conversation(
    request: Request,
    account_id: str,
    notebook_id: str,
    conversation_id: str,
):
    """Delete a conversation."""
    client = get_client(account_id)
    await client.chat.delete_conversation(notebook_id, conversation_id)
    return {"response_time_ms": elapsed_ms(request), "success": True}

@router.post("/notebooks/{notebook_id}/chat/configure")
async def configure_chat(
    request: Request,
    account_id: str,
    notebook_id: str,
    body: ConfigureChatRequest,
):
    """Configure chat settings."""
    client = get_client(account_id)
    await client.chat.configure(
        notebook_id=notebook_id,
        goal=ChatGoal.CUSTOM,
        response_length=ChatResponseLength.DEFAULT,
        custom_prompt=body.custom_prompt
    )
    return {"response_time_ms": elapsed_ms(request), "success": True}