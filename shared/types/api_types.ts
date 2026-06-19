/**
 * Campus Query AI Assistant - Shared API Types
 * TypeScript type definitions for API requests and responses
 */

// ============================================================================
// Request Types
// ============================================================================

export interface ChatQueryRequest {
  question: string;
  session_id?: string;
  context?: Record<string, any>;
}

export interface FeedbackRequest {
  rating: number; // 1-5
  comment?: string;
  conversation_id?: number;
}

export interface LoginRequest {
  email: string;
  password: string;
}

export interface RegisterRequest {
  email: string;
  password: string;
  name?: string;
}

export interface UpdateUserRequest {
  name?: string;
  email?: string;
  password?: string;
}

// ============================================================================
// Response Types
// ============================================================================

export interface ApiResponse<T = any> {
  success: boolean;
  data?: T;
  error?: string;
  message?: string;
  timestamp?: string;
}

export interface ChatQueryResponse {
  answer: string;
  sources?: string[];
  confidence?: number;
  session_id?: string;
  timestamp?: string;
}

export interface HealthResponse {
  status: string;
  version: string;
  timestamp: string;
  database: boolean;
  ollama: boolean;
}

export interface User {
  id: number;
  email: string;
  name?: string;
  created_at: string;
  updated_at: string;
}

export interface AuthResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
  user: User;
}

export interface Conversation {
  id: number;
  title?: string;
  created_at: string;
  updated_at: string;
  message_count?: number;
}

export interface Message {
  id: number;
  conversation_id: number;
  role: 'user' | 'assistant' | 'system';
  content: string;
  created_at: string;
}

export interface ChatHistoryResponse {
  conversations: Conversation[];
  total: number;
}

export interface FAQ {
  id: number;
  question: string;
  answer?: string;
  frequency: number;
  last_asked?: string;
}

export interface FAQListResponse {
  faqs: FAQ[];
  total: number;
}

export interface FeedbackItem {
  id: number;
  rating: number;
  comment?: string;
  created_at: string;
  user_id?: number;
}

export interface AnalyticsOverview {
  total_users: number;
  total_queries: number;
  total_feedback: number;
  avg_rating: number;
  queries_today: number;
  queries_this_week: number;
  queries_this_month: number;
}

// ============================================================================
// WebSocket Types
// ============================================================================

export enum WebSocketMessageType {
  QUERY = 'query',
  RESPONSE = 'response',
  ERROR = 'error',
  PING = 'ping',
  PONG = 'pong',
  TYPING = 'typing',
  CONNECTED = 'connected',
  FEEDBACK = 'feedback',
}

export interface WebSocketMessage<T = any> {
  type: WebSocketMessageType;
  data: T;
  timestamp?: string;
  message_id?: string;
}

export interface WSQueryData {
  question: string;
  session_id?: string;
  context?: Record<string, any>;
}

export interface WSResponseData {
  answer: string;
  sources?: string[];
  confidence?: number;
}

export interface WSErrorData {
  error: string;
  error_code?: string;
  details?: Record<string, any>;
}

export interface WSTypingData {
  is_typing: boolean;
}

// ============================================================================
// Error Types
// ============================================================================

export interface ApiError {
  error: string;
  error_code?: string;
  details?: Record<string, any>;
  status_code?: number;
}

export enum ErrorCode {
  INVALID_INPUT = 'INVALID_INPUT',
  AUTHENTICATION_FAILED = 'AUTHENTICATION_FAILED',
  AUTHORIZATION_FAILED = 'AUTHORIZATION_FAILED',
  RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND',
  DATABASE_ERROR = 'DATABASE_ERROR',
  EXTERNAL_API_ERROR = 'EXTERNAL_API_ERROR',
  RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED',
  VALIDATION_ERROR = 'VALIDATION_ERROR',
}

// ============================================================================
// Utility Types
// ============================================================================

export type PaginationParams = {
  page?: number;
  limit?: number;
  offset?: number;
};

export type SortParams = {
  sort_by?: string;
  sort_order?: 'asc' | 'desc';
};

export type FilterParams = {
  search?: string;
  start_date?: string;
  end_date?: string;
  [key: string]: any;
};

export type QueryParams = PaginationParams & SortParams & FilterParams;

// ============================================================================
// HTTP Client Types
// ============================================================================

export interface RequestConfig {
  method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH';
  headers?: Record<string, string>;
  params?: QueryParams;
  data?: any;
  timeout?: number;
}

export interface RequestOptions {
  baseURL?: string;
  timeout?: number;
  headers?: Record<string, string>;
  withCredentials?: boolean;
}
