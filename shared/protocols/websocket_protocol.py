"""
Campus Query AI Assistant - WebSocket Protocol Definitions
Defines message formats for WebSocket communication between clients and backend.
"""

from enum import Enum
from typing import Optional, Dict, Any
from datetime import datetime


class MessageType(str, Enum):
    """WebSocket message types."""
    # Client -> Server
    QUERY = "query"  # User asks a question
    PING = "ping"  # Keep-alive ping
    AUTH = "auth"  # Authentication message
    FEEDBACK = "feedback"  # User provides feedback
    
    # Server -> Client
    RESPONSE = "response"  # AI response
    ERROR = "error"  # Error message
    PONG = "pong"  # Keep-alive pong
    TYPING = "typing"  # Server is processing
    CONNECTED = "connected"  # Connection established


class WebSocketMessage:
    """Base WebSocket message structure."""
    
    def __init__(
        self,
        type: MessageType,
        data: Dict[str, Any],
        timestamp: Optional[str] = None,
        message_id: Optional[str] = None
    ):
        self.type = type
        self.data = data
        self.timestamp = timestamp or datetime.utcnow().isoformat()
        self.message_id = message_id
    
    def to_dict(self) -> Dict[str, Any]:
        """Convert to dictionary for JSON serialization."""
        return {
            "type": self.type.value,
            "data": self.data,
            "timestamp": self.timestamp,
            "message_id": self.message_id
        }
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'WebSocketMessage':
        """Create from dictionary."""
        return cls(
            type=MessageType(data["type"]),
            data=data["data"],
            timestamp=data.get("timestamp"),
            message_id=data.get("message_id")
        )


class QueryMessage(WebSocketMessage):
    """User query message."""
    
    def __init__(
        self,
        question: str,
        session_id: Optional[str] = None,
        context: Optional[Dict[str, Any]] = None,
        message_id: Optional[str] = None
    ):
        super().__init__(
            type=MessageType.QUERY,
            data={
                "question": question,
                "session_id": session_id,
                "context": context or {}
            },
            message_id=message_id
        )


class ResponseMessage(WebSocketMessage):
    """AI response message."""
    
    def __init__(
        self,
        answer: str,
        sources: Optional[list] = None,
        confidence: Optional[float] = None,
        message_id: Optional[str] = None
    ):
        super().__init__(
            type=MessageType.RESPONSE,
            data={
                "answer": answer,
                "sources": sources or [],
                "confidence": confidence
            },
            message_id=message_id
        )


class ErrorMessage(WebSocketMessage):
    """Error message."""
    
    def __init__(
        self,
        error: str,
        error_code: Optional[str] = None,
        details: Optional[Dict[str, Any]] = None,
        message_id: Optional[str] = None
    ):
        super().__init__(
            type=MessageType.ERROR,
            data={
                "error": error,
                "error_code": error_code,
                "details": details or {}
            },
            message_id=message_id
        )


class FeedbackMessage(WebSocketMessage):
    """User feedback message."""
    
    def __init__(
        self,
        rating: int,  # 1-5
        comment: Optional[str] = None,
        message_id: Optional[str] = None
    ):
        super().__init__(
            type=MessageType.FEEDBACK,
            data={
                "rating": rating,
                "comment": comment
            },
            message_id=message_id
        )


# Example usage:
"""
# Client sends query
query = QueryMessage(
    question="What programs does MMU offer?",
    session_id="abc123"
)
websocket.send(json.dumps(query.to_dict()))

# Server sends response
response = ResponseMessage(
    answer="MMU offers programs in Computing, Business, and Science...",
    sources=["mmu_basics.json"],
    confidence=0.95
)
websocket.send(json.dumps(response.to_dict()))

# Client sends feedback
feedback = FeedbackMessage(rating=5, comment="Very helpful!")
websocket.send(json.dumps(feedback.to_dict()))
"""
