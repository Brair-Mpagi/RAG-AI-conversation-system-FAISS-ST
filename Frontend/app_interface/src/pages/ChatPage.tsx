import { useState, useCallback } from 'react'
import styled from 'styled-components'
import { useChatStore } from '../store/chatStore'
import { useChat } from '../hooks/useChat'
import type { AppSettings } from '../hooks/useSettings'
import type { SessionInfo } from '../hooks/useSession'
import MessageList from '../components/chat/MessageList'
import ChatInput from '../components/chat/ChatInput'
import SuggestedQuestions from '../components/chat/SuggestedQuestions'
import EscalateModal from '../components/modals/EscalateModal'
import QRCodePanel from '../components/ui/QRCodePanel'

// ── Styled ────────────────────────────────────────────────
const PageRoot = styled.div`
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--bg-main);
  overflow: hidden;
`

const ChatHeader = styled.div`
  height: var(--header-h);
  display: flex;
  align-items: center;
  padding: 0 20px;
  border-bottom: 1px solid var(--border);
  background: var(--bg-sidebar);
  flex-shrink: 0;
  gap: 12px;

  @media (max-width: 768px) { display: none; }
`

const AvatarRing = styled.div`
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: var(--grad-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 15px;
  box-shadow: var(--shadow-md);
  position: relative;

  &::after {
    content: '';
    position: absolute;
    bottom: 1px;
    right: 1px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--online);
    border: 2px solid var(--bg-sidebar);
  }
`

const HeaderInfo = styled.div`
  h2 { font-size: 15px; font-weight: 700; color: var(--text-primary); }
  p  { font-size: 11px; color: var(--online); font-weight: 500; margin-top: 1px; }
`

const HeaderActions = styled.div`
  margin-left: auto;
  display: flex;
  gap: 6px;
`

const IconBtn = styled.button`
  width: 34px;
  height: 34px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--bg-hover);
  color: var(--text-secondary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  cursor: pointer;
  transition: all var(--transition);
  &:hover { background: var(--bg-active); color: var(--primary); }
`

const EmptyState = styled.div`
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 24px;
  padding: 32px 20px;
  overflow-y: auto;
`

const WelcomeCard = styled.div`
  text-align: center;
  max-width: 480px;

  .icon-ring {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: var(--grad-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    color: #fff;
    margin: 0 auto 18px;
    box-shadow: var(--shadow-lg);
  }

  h1 {
    font-size: 22px;
    font-weight: 800;
    color: var(--text-primary);
    margin-bottom: 8px;
    background: var(--grad-primary);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }

  p {
    font-size: 14px;
    color: var(--text-secondary);
    line-height: 1.6;
    max-width: 360px;
    margin: 0 auto;
  }
`

const NoConvState = styled.div`
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
  color: var(--text-muted);
  text-align: center;
  padding: 40px;

  i { font-size: 40px; opacity: 0.3; }
  h3 { font-size: 16px; font-weight: 700; color: var(--text-secondary); }
  p  { font-size: 13px; line-height: 1.6; max-width: 280px; }
`

const NewChatFab = styled.button`
  padding: 10px 22px;
  border-radius: var(--radius-full);
  border: none;
  background: var(--grad-primary);
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: var(--shadow-md);
  transition: all var(--transition);
  &:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
`

// ── Component ─────────────────────────────────────────────
interface ChatViewProps {
  settings: AppSettings
  session: SessionInfo
}

export default function ChatView({ settings, session }: ChatViewProps) {
  const [showEscalate, setShowEscalate] = useState(false)
  const [showQR, setShowQR]             = useState(false)
  const {
    activeConversationId,
    getActiveMessages,
    getActiveConversation,
    newConversation,
    setActiveConversation,
  } = useChatStore()

  const { loading, isRateLimited, rateLimitResetsAt, sendMessage, regenerate } = useChat()

  const messages = getActiveMessages()
  const conversation = getActiveConversation()
  const backendConvId = conversation?.backendConversationId ?? null

  const handleSend = useCallback(
    (prompt: string) => sendMessage({ prompt, sessionId: session.sessionId }),
    [sendMessage, session.sessionId]
  )

  const handleRegenerate = useCallback(
    (originalPrompt: string) =>
      regenerate({ prompt: originalPrompt, sessionId: session.sessionId }, originalPrompt),
    [regenerate, session.sessionId]
  )

  const handleNewChat = () => {
    const id = newConversation()
    setActiveConversation(id)
  }

  // No conversation selected yet
  if (!activeConversationId) {
    return (
      <PageRoot>
        <NoConvState>
          <i className="fas fa-comment-dots" />
          <h3>No conversation selected</h3>
          <p>Start a new chat or pick a conversation from the sidebar.</p>
          <NewChatFab onClick={handleNewChat}>
            <i className="fas fa-plus" /> New Chat
          </NewChatFab>
        </NoConvState>
      </PageRoot>
    )
  }

  return (
    <PageRoot>
      {/* Header bar (desktop only) */}
      <ChatHeader>
        <AvatarRing><i className="fas fa-graduation-cap" /></AvatarRing>
        <HeaderInfo>
          <h2>Campus Assistant</h2>
          <p>● Online</p>
        </HeaderInfo>
        <HeaderActions>
          <IconBtn onClick={handleNewChat} title="New conversation">
            <i className="fas fa-plus" />
          </IconBtn>
          <IconBtn onClick={() => setShowEscalate(true)} title="Contact support">
            <i className="fas fa-life-ring" />
          </IconBtn>
          {/* @ts-ignore */}
          {!(typeof window !== 'undefined' && window.Capacitor?.isNativePlatform()) && (
            <IconBtn onClick={() => setShowQR(true)} title="Install app (QR code)" style={{ color: 'var(--primary)' }}>
              <i className="fas fa-qrcode" />
            </IconBtn>
          )}
        </HeaderActions>
      </ChatHeader>

      {/* Messages or empty state */}
      {messages.length === 0 ? (
        <EmptyState>
          <WelcomeCard>
            <div className="icon-ring"><i className="fas fa-graduation-cap" /></div>
            <h1>Hello! I'm MMU Assistant</h1>
            <p>
              Ask me anything about Mountains of the Moon University — programs,
              admissions, fees, campus life, and more.
            </p>
          </WelcomeCard>
          <SuggestedQuestions onSelect={handleSend} disabled={loading} />
        </EmptyState>
      ) : (
        <MessageList
          messages={messages}
          loading={loading}
          bubbleStyle={settings.bubbleStyle}
          sessionId={session.sessionId}
          conversationId={backendConvId}
          onRegenerate={handleRegenerate}
        />
      )}

      {/* Input */}
      <ChatInput
        onSend={handleSend}
        onEscalate={() => setShowEscalate(true)}
        disabled={loading}
        isRateLimited={isRateLimited}
        rateLimitResetsAt={rateLimitResetsAt}
      />

      {/* Escalate modal */}
      <EscalateModal
        isVisible={showEscalate}
        onClose={() => setShowEscalate(false)}
        sessionId={session.sessionId}
        conversationId={backendConvId}
      />

      {/* QR install panel */}
      <QRCodePanel isVisible={showQR} onClose={() => setShowQR(false)} />
    </PageRoot>
  )
}
