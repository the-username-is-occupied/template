"""
ChatAPI endpoints - wraps notebooklm-py ChatAPI.
"""
import time

from fastapi import APIRouter, Depends, HTTPException, Request
from main import get_client, app
from models import (
    AskRequest,
    AskResponse,
    AskResult,
    ChatReference,
)


router = APIRouter()


@router.post("/notebooks/ask", response_model=AskResponse)
async def ask_question(
    request: Request,
    account_id: str,
    body: AskRequest
):
    """Ask a question in a notebook."""
    client = get_client(account_id)
    
    last_conv_id = await client.chat.get_conversation_id(body.notebook_id)
    if last_conv_id:
        await client.chat.delete_conversation(body.notebook_id, last_conv_id)

    result = await client.chat.ask(
        notebook_id=body.notebook_id,
        question=body.question,
        source_ids=body.source_ids,
        conversation_id=body.conversation_id
    )
    
    # Calculate response time after the request is complete
    response_time_ms = int((time.time() - request.state.start_time) * 1000)
    
    # Handle both dict and object returns
    if isinstance(result, dict):
        answer = result.get("answer", "")
        conversation_id = result.get("conversation_id", "")
        turn_number = result.get("turn_number", 0)
        is_follow_up = result.get("is_follow_up", False)
        references_data = result.get("references", [])
    else:
        answer = getattr(result, 'answer', "")
        conversation_id = getattr(result, 'conversation_id', "")
        turn_number = getattr(result, 'turn_number', 0)
        is_follow_up = getattr(result, 'is_follow_up', False)
        references_data = getattr(result, 'references', [])
    
    # Parse references
    references = []
    for ref in references_data:
        if isinstance(ref, dict):
            chat_ref = ChatReference(
                source_id=ref.get("source_id"),
                citation_number=ref.get("citation_number"),
                cited_text=ref.get("cited_text"),
                start_char=ref.get("start_char"),
                end_char=ref.get("end_char"),
                chunk_id=ref.get("chunk_id")
            )
        else:
            chat_ref = ChatReference(
                source_id=getattr(ref, 'source_id', None),
                citation_number=getattr(ref, 'citation_number', None),
                cited_text=getattr(ref, 'cited_text', None),
                start_char=getattr(ref, 'start_char', None),
                end_char=getattr(ref, 'end_char', None),
                chunk_id=getattr(ref, 'chunk_id', None)
            )
        references.append(chat_ref)
    
    ask_result = AskResult(
        answer=answer,
        conversation_id=conversation_id,
        turn_number=turn_number,
        is_follow_up=is_follow_up,
        references=references
    )
    
    return AskResponse(
        response_time_ms=response_time_ms,
        result=ask_result
    )


@router.get("/notebooks/{notebook_id}/chat/history")
async def get_chat_history(request: Request, account_id: str, notebook_id: str):
    """Get chat history."""
    client = get_client(account_id)
    
    history = await client.chat.get_history(notebook_id)
    
    response_time_ms = int((time.time() - request.state.start_time) * 1000)
    
    return {
        "response_time_ms": response_time_ms,
        "history": history
    }


@router.delete("/notebooks/{notebook_id}/chat/conversation")
async def delete_conversation(request: Request, account_id: str, notebook_id: str, conversation_id: str):
    """Delete a conversation."""
    client = get_client(account_id)
    
    await client.chat.delete_conversation(notebook_id, conversation_id)
    
    response_time_ms = int((time.time() - request.state.start_time) * 1000)
    
    return {
        "response_time_ms": response_time_ms,
        "success": True
    }
