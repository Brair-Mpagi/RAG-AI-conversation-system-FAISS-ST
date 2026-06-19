import { create } from 'zustand'
import { v4 as uuidv4 } from 'uuid'

// ── Types ─────────────────────────────────────────────────
export interface Message {
  id: string
  role: 'user' | 'assistant'
  content: string
  timestamp: string
  message_id?: number       // backend message_id for reactions
  isError?: boolean
}

export interface Conversation {
  id: string                 // local UUID
  title: string              // derived from first user message
  createdAt: string
  updatedAt: string
  backendConversationId?: number | null
  messageCount: number
}

interface ChatState {
  conversations: Conversation[]
  activeConversationId: string | null
  messages: Record<string, Message[]>   // keyed by conversation.id

  // Actions
  newConversation: () => string
  setActiveConversation: (id: string | null) => void
  deleteConversation: (id: string) => void
  clearAllHistory: () => void

  addMessage: (conversationId: string, msg: Omit<Message, 'id'>) => Message
  updateConversationTitle: (id: string, title: string) => void
  setBackendConversationId: (localId: string, backendId: number) => void

  getActiveMessages: () => Message[]
  getActiveConversation: () => Conversation | null
}

// ── Persist helpers ───────────────────────────────────────
const STORAGE_KEY = 'mmu_app_chats'

function loadFromStorage(): Pick<ChatState, 'conversations' | 'messages' | 'activeConversationId'> {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) return JSON.parse(raw)
  } catch { /* ignore */ }
  return { conversations: [], messages: {}, activeConversationId: null }
}

function saveToStorage(state: Pick<ChatState, 'conversations' | 'messages' | 'activeConversationId'>) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
  } catch { /* storage full or unavailable */ }
}

// ── Store ─────────────────────────────────────────────────
const initial = loadFromStorage()

export const useChatStore = create<ChatState>((set, get) => ({
  conversations: initial.conversations,
  activeConversationId: initial.activeConversationId,
  messages: initial.messages,

  newConversation: () => {
    const id = uuidv4()
    const now = new Date().toISOString()
    const conv: Conversation = {
      id,
      title: 'New conversation',
      createdAt: now,
      updatedAt: now,
      messageCount: 0,
    }
    set((s) => {
      const next = {
        conversations: [conv, ...s.conversations],
        messages: { ...s.messages, [id]: [] },
        activeConversationId: id,
      }
      saveToStorage(next)
      return next
    })
    return id
  },

  setActiveConversation: (id) => {
    set((s) => {
      const next = { ...s, activeConversationId: id }
      saveToStorage(next)
      return next
    })
  },

  deleteConversation: (id) => {
    set((s) => {
      const conversations = s.conversations.filter((c) => c.id !== id)
      const messages = { ...s.messages }
      delete messages[id]
      const activeConversationId =
        s.activeConversationId === id
          ? (conversations[0]?.id ?? null)
          : s.activeConversationId
      const next = { conversations, messages, activeConversationId }
      saveToStorage(next)
      return next
    })
  },

  clearAllHistory: () => {
    const next = { conversations: [], messages: {}, activeConversationId: null }
    localStorage.removeItem(STORAGE_KEY)
    set(next)
  },

  addMessage: (conversationId, msg) => {
    const id = uuidv4()
    const message: Message = { id, ...msg }
    set((s) => {
      const existing = s.messages[conversationId] ?? []
      const updatedMessages = { ...s.messages, [conversationId]: [...existing, message] }
      const updatedConversations = s.conversations.map((c) =>
        c.id === conversationId
          ? { ...c, updatedAt: msg.timestamp, messageCount: (c.messageCount || 0) + 1 }
          : c
      )
      const next = {
        conversations: updatedConversations,
        messages: updatedMessages,
        activeConversationId: s.activeConversationId,
      }
      saveToStorage(next)
      return next
    })
    return message
  },

  updateConversationTitle: (id, title) => {
    set((s) => {
      const conversations = s.conversations.map((c) => (c.id === id ? { ...c, title } : c))
      const next = { ...s, conversations }
      saveToStorage(next)
      return next
    })
  },

  setBackendConversationId: (localId, backendId) => {
    set((s) => {
      const conversations = s.conversations.map((c) =>
        c.id === localId ? { ...c, backendConversationId: backendId } : c
      )
      const next = { ...s, conversations }
      saveToStorage(next)
      return next
    })
  },

  getActiveMessages: () => {
    const s = get()
    return s.activeConversationId ? (s.messages[s.activeConversationId] ?? []) : []
  },

  getActiveConversation: () => {
    const s = get()
    return s.conversations.find((c) => c.id === s.activeConversationId) ?? null
  },
}))
