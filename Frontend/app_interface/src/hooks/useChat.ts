import { useState, useCallback, useRef } from 'react'
import { chatAPI } from '../api/client'
import { useChatStore } from '../store/chatStore'

const RATE_LIMIT_WINDOW = 300_000 // 5 min
const RATE_LIMIT_MAX    = 30

export interface SendOptions {
  prompt: string
  sessionId: number | null
}

export interface ChatHook {
  loading: boolean
  error: string | null
  isRateLimited: boolean
  rateLimitResetsAt: number | null
  sendMessage: (opts: SendOptions) => Promise<void>
  regenerate: (opts: SendOptions, originalPrompt: string) => Promise<void>
  clearError: () => void
}

export function useChat(): ChatHook {
  const [loading, setLoading]             = useState(false)
  const [error, setError]                 = useState<string | null>(null)
  const [rateLimitResetsAt, setRateLimitResetsAt] = useState<number | null>(null)
  const requestTimestamps = useRef<number[]>([])

  const {
    activeConversationId,
    addMessage,
    updateConversationTitle,
    setBackendConversationId,
    getActiveConversation,
    newConversation,
  } = useChatStore()

  const isRateLimited = !!(rateLimitResetsAt && Date.now() < rateLimitResetsAt)

  // Client-side rate limit guard
  const checkClientRateLimit = (): boolean => {
    const now = Date.now()
    requestTimestamps.current = requestTimestamps.current.filter((t) => now - t < RATE_LIMIT_WINDOW)
    if (requestTimestamps.current.length >= RATE_LIMIT_MAX) return false
    requestTimestamps.current.push(now)
    return true
  }

  const doSend = useCallback(
    async (prompt: string, sessionId: number | null, conversationId: string) => {
      const conversation = useChatStore.getState().conversations.find((c) => c.id === conversationId)
      const backendConvId = conversation?.backendConversationId ?? null

      // Build history from existing messages
      const existingMessages = useChatStore.getState().messages[conversationId] ?? []
      const history = existingMessages.slice(-20).flatMap((m) => [m.content])

      try {
        const res = await chatAPI.send({
          prompt,
          session_id: sessionId,
          interface_type: 'web',
          conversation_id: backendConvId,
          history,
        })

        const responseText = res.response || 'No response received.'
        if (typeof res.conversation_id === 'number') {
          setBackendConversationId(conversationId, res.conversation_id)
        }

        addMessage(conversationId, {
          role: 'assistant',
          content: responseText,
          timestamp: new Date().toISOString(),
          message_id: res.message_id ?? undefined,
        })
      } catch (e: unknown) {
        const err = e as { response?: { status?: number; data?: { detail?: string }; headers?: { 'retry-after'?: string } }; code?: string; message?: string }
        let errText = 'Sorry, something went wrong. Please try again.'

        if (err.response?.status === 429) {
          const retry = parseInt(err.response.headers?.['retry-after'] || '60', 10)
          setRateLimitResetsAt(Date.now() + retry * 1000)
          errText = `⏱️ Rate limit reached — please wait ${retry} seconds.`
        } else if (err.code === 'ERR_NETWORK' || err.message?.includes('Network Error')) {
          errText = '⚠️ Cannot reach the server. Check your connection and try again.'
        } else if (err.response?.status === 500) {
          errText = '⚠️ Server error. Our team has been notified.'
        } else if (err.response?.data?.detail) {
          errText = `⚠️ ${err.response.data.detail}`
        } else if (err.code === 'ECONNABORTED') {
          errText = '⏱️ Request timed out. The AI model is taking too long — please try again.'
        }

        addMessage(conversationId, {
          role: 'assistant',
          content: errText,
          timestamp: new Date().toISOString(),
          isError: true,
        })
        setError(errText)
      }
    },
    [addMessage, setBackendConversationId]
  )

  const sendMessage = useCallback(
    async ({ prompt, sessionId }: SendOptions) => {
      if (!prompt.trim() || loading) return
      if (isRateLimited) { setError(`Rate limited — please wait.`); return }
      if (!checkClientRateLimit()) {
        setError('Too many messages. Please wait a moment.')
        return
      }

      setError(null)

      // Ensure there's an active conversation
      let convId = activeConversationId
      if (!convId) {
        convId = useChatStore.getState().newConversation()
      }

      // Add user message
      addMessage(convId, {
        role: 'user',
        content: prompt,
        timestamp: new Date().toISOString(),
      })

      // Set title from first message
      const conv = useChatStore.getState().conversations.find((c) => c.id === convId)
      if (conv && conv.title === 'New conversation') {
        updateConversationTitle(convId, prompt.slice(0, 50) + (prompt.length > 50 ? '…' : ''))
      }

      setLoading(true)
      await doSend(prompt, sessionId, convId)
      setLoading(false)
    },
    [loading, isRateLimited, activeConversationId, addMessage, updateConversationTitle, doSend, newConversation]
  )

  const regenerate = useCallback(
    async ({ sessionId }: SendOptions, originalPrompt: string) => {
      if (loading || !activeConversationId) return
      setError(null)
      setLoading(true)
      await doSend(originalPrompt, sessionId, activeConversationId)
      setLoading(false)
    },
    [loading, activeConversationId, doSend]
  )

  return {
    loading,
    error,
    isRateLimited,
    rateLimitResetsAt,
    sendMessage,
    regenerate,
    clearError: () => setError(null),
  }
}
