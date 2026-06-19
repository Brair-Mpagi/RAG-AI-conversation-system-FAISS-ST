import { useEffect, useRef } from 'react'
import styled from 'styled-components'
import type { Message } from '../../store/chatStore'
import MessageBubble from './MessageBubble'
import TypingIndicator from './TypingIndicator'

const List = styled.div`
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 20px 20px 8px;
  display: flex;
  flex-direction: column;
  gap: var(--msg-gap, 10px);
  -webkit-overflow-scrolling: touch;

  @media (max-width: 640px) { 
    padding: 16px 14px 12px;
  }

  &::-webkit-scrollbar { width: 5px; }
  &::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
`

interface MessageListProps {
  messages: Message[]
  loading: boolean
  bubbleStyle: 'rounded' | 'flat'
  sessionId: number | null
  conversationId: number | null | undefined
  onRegenerate: (originalPrompt: string) => void
}

export default function MessageList({
  messages,
  loading,
  bubbleStyle,
  sessionId,
  conversationId,
  onRegenerate,
}: MessageListProps) {
  const bottomRef = useRef<HTMLDivElement>(null)

  // Smooth auto-scroll to bottom on new messages
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [messages, loading])

  return (
    <List>
      {messages.map((msg, idx) => {
        // Find the previous user message for regenerate
        const prevUser =
          msg.role === 'assistant' && idx > 0 && messages[idx - 1].role === 'user'
            ? messages[idx - 1]
            : null

        return (
          <MessageBubble
            key={msg.id}
            message={msg}
            bubbleStyle={bubbleStyle}
            sessionId={sessionId}
            conversationId={conversationId}
            onRegenerate={prevUser ? () => onRegenerate(prevUser.content) : undefined}
          />
        )
      })}

      {loading && <TypingIndicator />}
      <div ref={bottomRef} style={{ height: 1 }} />
    </List>
  )
}
