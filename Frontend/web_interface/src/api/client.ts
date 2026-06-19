import axios from 'axios'

// Smart BASE_URL detection
// In development (localhost), use empty string to leverage Vite proxy
// For production or external access, construct the full URL
function getBaseURL(): string {
  const hostname = window.location.hostname
  const isDev = import.meta.env.DEV

  console.log('🔍 Detecting BASE_URL:', { hostname, isDev, env: import.meta.env.VITE_API_BASE_URL })

  // For localhost development, use empty baseURL to let Vite proxy handle it
  if (isDev && (hostname === 'localhost' || hostname === '127.0.0.1')) {
    console.log('✅ Using Vite proxy (empty baseURL)')
    return '' // Empty string uses Vite proxy
  }

  // If accessed via IP address, use that same IP for backend
  if (/^\d+\.\d+\.\d+\.\d+$/.test(hostname)) {
    const url = `http://${hostname}:8000`
    console.log('✅ Using IP-based URL:', url)
    return url
  }

  // Fallback to env variable or default
  const fallback = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'
  console.log('✅ Using fallback URL:', fallback)
  return fallback
}

const BASE_URL = getBaseURL()

// Debug logging
console.log('🔧 API Client Configuration:', {
  BASE_URL: BASE_URL || '(using Vite proxy)',
  fullURL: BASE_URL ? `${BASE_URL}/api/v1/chat` : '/api/v1/chat',
  hostname: window.location.hostname,
  origin: window.location.origin,
  env: import.meta.env.VITE_API_BASE_URL,
  mode: import.meta.env.MODE
})

export const api = axios.create({
  baseURL: BASE_URL,
  headers: {
    'Content-Type': 'application/json'
  },
  timeout: 200000, // 200 second timeout — CPU-only Ollama needs 60-180s per response
})

// Add request interceptor for debugging
api.interceptors.request.use(
  (config) => {
    console.log('📤 API Request:', config.method?.toUpperCase(), config.url, 'to', config.baseURL)
    return config
  },
  (error) => {
    console.error('❌ Request Error:', error)
    return Promise.reject(error)
  }
)

// Add response interceptor for debugging
api.interceptors.response.use(
  (response) => {
    console.log('✅ API Response:', response.status, response.config.url)
    return response
  },
  (error) => {
    console.error('❌ API Error:', {
      message: error.message,
      code: error.code,
      url: error.config?.url,
      baseURL: error.config?.baseURL,
      status: error.response?.status
    })
    return Promise.reject(error)
  }
)

export type ChatRequest = {
  prompt: string
  history?: string[]
  conversation_id?: number | null
  session_id?: number | null
  interface_type?: 'web' | 'mobile'
}

export type ChatResponse = {
  response: string
  conversation_id?: number | null
  message_id?: number | null
}

export async function chat(req: ChatRequest): Promise<ChatResponse> {
  const { data } = await api.post<ChatResponse>('/api/v1/chat', req)
  return data
}

export type SessionStartRequest = {
  user_id?: number | null
  guest_id?: number | null
  interface_type?: 'web' | 'mobile'
  access_mode?: 'guest' | 'registered'
  device_type?: string
  device_model?: string
  device_brand?: string
  os_name?: string
  os_version?: string
  browser_name?: string
  browser_version?: string
  app_version?: string
  screen_resolution?: string
  location?: string
}

export type SessionStartResponse = {
  session_id: number
  session_token: string
}

export async function startSession(payload: SessionStartRequest): Promise<SessionStartResponse> {
  const { data } = await api.post<SessionStartResponse>('/api/v1/sessions/start', payload)
  return data
}

export type HeartbeatRequest = {
  session_id: number
  session_token: string
}

export type HeartbeatResponse = {
  status: string
  message: string
}

export async function sendHeartbeat(payload: HeartbeatRequest): Promise<HeartbeatResponse> {
  const { data } = await api.post<HeartbeatResponse>('/api/v1/sessions/heartbeat', payload)
  return data
}

export type ForwardQueryRequest = {
  username?: string
  email?: string
  query: string
  session_id?: number
  conversation_id?: number
  message_id?: number
  interface_type?: 'web' | 'mobile'
}

export type ForwardQueryResponse = {
  status: string
  query_id: number
}

export async function forwardQuery(payload: ForwardQueryRequest): Promise<ForwardQueryResponse> {
  const { data } = await api.post<ForwardQueryResponse>('/api/v1/queries/forward', payload)
  return data
}

// --- Feedback & Reaction API ---

export type ReactionRequest = {
  message_id: number
  reaction_type: 'thumbs_up' | 'thumbs_down'
  session_id: number
}

export type ReactionResponse = {
  status: string
  message: string
}

export async function submitReaction(payload: ReactionRequest): Promise<ReactionResponse> {
  const { data } = await api.post<ReactionResponse>('/api/v1/feedback/reaction', payload)
  return data
}

export type DetailedFeedbackRequest = {
  message_id: number
  conversation_id: number
  session_id: number
  category: string
  comment: string
  rating: string
}

export type DetailedFeedbackResponse = {
  status: string
  message: string
  feedback_id: number
}

export async function submitDetailedFeedback(payload: DetailedFeedbackRequest): Promise<DetailedFeedbackResponse> {
  const { data } = await api.post<DetailedFeedbackResponse>('/api/v1/feedback/detailed', payload)
  return data
}
