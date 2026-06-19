from __future__ import annotations

from typing import Optional
from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.orm import Session

from databases.session import get_db
from models.feedback import Feedback, MessageReaction

router = APIRouter(prefix="/api/v1/feedback", tags=["feedback"])


class ReactionRequest(BaseModel):
    message_id: int
    reaction_type: str  # 'thumbs_up' or 'thumbs_down'
    session_id: int


class FeedbackRequest(BaseModel):
    message_id: int
    conversation_id: int
    session_id: int
    category: str
    comment: str
    rating: str


@router.post("/reaction", status_code=status.HTTP_201_CREATED)
def submit_reaction(payload: ReactionRequest, db: Session = Depends(get_db)):
    """Submit a thumbs up/down reaction to a message"""
    
    # Check if reaction already exists for this message and session
    existing_reaction = db.query(MessageReaction).filter(
        MessageReaction.message_id == payload.message_id,
        MessageReaction.session_id == payload.session_id
    ).first()
    
    if existing_reaction:
        # Update existing reaction
        existing_reaction.reaction_type = payload.reaction_type
        existing_reaction.created_at = datetime.utcnow()
        db.commit()
    else:
        # Create new reaction — session_id links directly to web_sessions
        reaction = MessageReaction(
            message_id=payload.message_id,
            session_id=payload.session_id,
            reaction_type=payload.reaction_type
        )
        db.add(reaction)
        db.commit()
    
    return {"status": "success", "message": "Reaction submitted"}


@router.post("/detailed", status_code=status.HTTP_201_CREATED)
def submit_detailed_feedback(payload: FeedbackRequest, db: Session = Depends(get_db)):
    """Submit detailed feedback with comments and category"""
    
    # Create feedback entry — session_id is required (NOT NULL FK to web_sessions)
    feedback = Feedback(
        session_id=payload.session_id,
        message_id=payload.message_id,
        conversation_id=payload.conversation_id,
        rating=payload.rating,
        comment=payload.comment,
        category=payload.category,
    )
    
    db.add(feedback)
    db.commit()
    db.refresh(feedback)
    
    return {
        "status": "success",
        "message": "Feedback submitted",
        "feedback_id": feedback.feedback_id
    }


@router.delete("/reaction/{message_id}/{session_id}", status_code=status.HTTP_200_OK)
def delete_reaction(message_id: int, session_id: int, db: Session = Depends(get_db)):
    """Delete a reaction (for unsetting thumbs up/down)"""
    
    reaction = db.query(MessageReaction).filter(
        MessageReaction.message_id == message_id,
        MessageReaction.session_id == session_id
    ).first()
    
    if not reaction:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Reaction not found"
        )
    
    db.delete(reaction)
    db.commit()
    
    return {"status": "success", "message": "Reaction deleted"}
