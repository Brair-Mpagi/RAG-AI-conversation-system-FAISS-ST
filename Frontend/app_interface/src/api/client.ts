import axios from 'axios'

// ── Smart BASE_URL detection ──────────────────────────────
function getBaseURL(): string {
  const hostname = window.location.hostname
  const isDev = import.meta.env.DEV

  // If running natively in Capacitor, localhost means the phone itself.
  // We must use the LAN IP of your computer or the production URL.
  // @ts-ignore
  const isCapacitor = typeof window !== 'undefined' && window.Capacitor?.isNativePlatform()

  if (isCapacitor) {
    // FALLBACK IP for native Android testing on LAN (update this if your IP changes)
    return import.meta.env.VITE_API_BASE_URL || 'http://172.20.10.4:8000'
  }

  if (isDev && (hostname === 'localhost' || hostname === '127.0.0.1')) {
    return '' // Use Vite proxy
  }
  if (/^\d+\.\d+\.\d+\.\d+$/.test(hostname)) {
    return `http://${hostname}:8000`
  }
  return import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'
}

export const api = axios.create({
  baseURL: getBaseURL(),
  headers: { 'Content-Type': 'application/json' },
  timeout: 200000, // 200s — CPU-only Ollama can take 60-180s
})

// ── Interceptors ──────────────────────────────────────────
api.interceptors.response.use(
  (r) => r,
  (error) => {
    console.error('API Error:', {
      message: error.message,
      url: error.config?.url,
      status: error.response?.status,
    })
    return Promise.reject(error)
  }
)

// ── Types ─────────────────────────────────────────────────
export type ChatRequest = {
  prompt: string
  history?: string[]
  conversation_id?: number | null
  session_id?: number | null
  interface_type?: 'web' | 'mobile'
}

export type ChatResponse = {
  response: string
  response_type?: string
  intent?: string
  context_used?: boolean
  confidence?: number | null
  confidence_level?: string | null
  conversation_id?: number | null
  message_id?: number | null
}

export type SessionStartRequest = {
  interface_type?: 'web' | 'mobile'
  access_mode?: 'guest' | 'registered'
  device_type?: string
  device_model?: string
  device_brand?: string
  os_name?: string
  os_version?: string
  browser_name?: string
  browser_version?: string
  screen_resolution?: string
  location?: string
}

export type SessionStartResponse = {
  session_id: number
  session_token: string
}

export type HeartbeatRequest = {
  session_id: number
  session_token: string
}

export type ReactionRequest = {
  message_id: number
  reaction_type: 'thumbs_up' | 'thumbs_down'
  session_id: number
}

export type DetailedFeedbackRequest = {
  message_id: number
  conversation_id: number
  session_id: number
  category: string
  comment: string
  rating: string
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

// ── API calls ─────────────────────────────────────────────
export const chatAPI = {
  send: (req: ChatRequest) =>
    api.post<ChatResponse>('/api/v1/chat', req).then((r) => r.data),

  startSession: (payload: SessionStartRequest) =>
    api.post<SessionStartResponse>('/api/v1/sessions/start', payload).then((r) => r.data),

  heartbeat: (payload: HeartbeatRequest) =>
    api.post('/api/v1/sessions/heartbeat', payload).then((r) => r.data),

  submitReaction: (payload: ReactionRequest) =>
    api.post('/api/v1/feedback/reaction', payload).then((r) => r.data),

  submitDetailedFeedback: (payload: DetailedFeedbackRequest) =>
    api.post('/api/v1/feedback/detailed', payload).then((r) => r.data),

  forwardQuery: (payload: ForwardQueryRequest) =>
    api.post('/api/v1/queries/forward', payload).then((r) => r.data),
}
