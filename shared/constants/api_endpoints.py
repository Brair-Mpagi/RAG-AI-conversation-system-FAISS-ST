"""
Campus Query AI Assistant - API Endpoint Constants
Shared constants for API endpoints across frontend and backend.
"""

# Base URLs
API_VERSION = "v1"
API_BASE_PATH = f"/api/{API_VERSION}"

# Health & Status
HEALTH_ENDPOINT = f"{API_BASE_PATH}/health"
STATUS_ENDPOINT = f"{API_BASE_PATH}/status"

# Authentication
AUTH_LOGIN = f"{API_BASE_PATH}/auth/login"
AUTH_REGISTER = f"{API_BASE_PATH}/auth/register"
AUTH_REFRESH = f"{API_BASE_PATH}/auth/refresh"
AUTH_LOGOUT = f"{API_BASE_PATH}/auth/logout"

# Chat & Query
CHAT_QUERY = f"{API_BASE_PATH}/chat/query"
CHAT_HISTORY = f"{API_BASE_PATH}/chat/history"
CHAT_CONVERSATIONS = f"{API_BASE_PATH}/chat/conversations"
CHAT_DELETE = f"{API_BASE_PATH}/chat/delete"

# User Management
USERS_ME = f"{API_BASE_PATH}/users/me"
USERS_UPDATE = f"{API_BASE_PATH}/users/update"
USERS_DELETE = f"{API_BASE_PATH}/users/delete"

# Feedback
FEEDBACK_SUBMIT = f"{API_BASE_PATH}/feedback/submit"
FEEDBACK_LIST = f"{API_BASE_PATH}/feedback/list"

# FAQ
FAQ_LIST = f"{API_BASE_PATH}/faq/list"
FAQ_SEARCH = f"{API_BASE_PATH}/faq/search"
FAQ_POPULAR = f"{API_BASE_PATH}/faq/popular"

# Knowledge Base
KB_SEARCH = f"{API_BASE_PATH}/kb/search"
KB_CATEGORIES = f"{API_BASE_PATH}/kb/categories"

# Analytics (Admin)
ANALYTICS_OVERVIEW = f"{API_BASE_PATH}/analytics/overview"
ANALYTICS_USERS = f"{API_BASE_PATH}/analytics/users"
ANALYTICS_QUERIES = f"{API_BASE_PATH}/analytics/queries"

# WebSocket
WS_CHAT = "/ws"
WS_NOTIFICATIONS = "/ws/notifications"

# HTTP Methods
class HTTPMethod:
    GET = "GET"
    POST = "POST"
    PUT = "PUT"
    DELETE = "DELETE"
    PATCH = "PATCH"

# Response Status Codes
class StatusCode:
    OK = 200
    CREATED = 201
    NO_CONTENT = 204
    BAD_REQUEST = 400
    UNAUTHORIZED = 401
    FORBIDDEN = 403
    NOT_FOUND = 404
    CONFLICT = 409
    INTERNAL_ERROR = 500
    SERVICE_UNAVAILABLE = 503

# Error Codes
class ErrorCode:
    INVALID_INPUT = "INVALID_INPUT"
    AUTHENTICATION_FAILED = "AUTHENTICATION_FAILED"
    AUTHORIZATION_FAILED = "AUTHORIZATION_FAILED"
    RESOURCE_NOT_FOUND = "RESOURCE_NOT_FOUND"
    DATABASE_ERROR = "DATABASE_ERROR"
    EXTERNAL_API_ERROR = "EXTERNAL_API_ERROR"
    RATE_LIMIT_EXCEEDED = "RATE_LIMIT_EXCEEDED"
    VALIDATION_ERROR = "VALIDATION_ERROR"

# Request Headers
class Headers:
    AUTHORIZATION = "Authorization"
    CONTENT_TYPE = "Content-Type"
    ACCEPT = "Accept"
    USER_AGENT = "User-Agent"
    X_REQUEST_ID = "X-Request-ID"
    X_SESSION_ID = "X-Session-ID"

# Content Types
class ContentType:
    JSON = "application/json"
    FORM = "application/x-www-form-urlencoded"
    MULTIPART = "multipart/form-data"
    TEXT = "text/plain"

# Example usage in TypeScript (frontend):
"""
// TypeScript version
export const API_ENDPOINTS = {
    HEALTH: '/api/v1/health',
    CHAT_QUERY: '/api/v1/chat/query',
    CHAT_HISTORY: '/api/v1/chat/history',
    // ... etc
};

export const WS_ENDPOINTS = {
    CHAT: '/ws',
    NOTIFICATIONS: '/ws/notifications'
};
"""
