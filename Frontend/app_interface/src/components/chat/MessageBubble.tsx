import { useState, useCallback } from 'react'
import styled from 'styled-components'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { format } from 'date-fns'
import type { Message } from '../../store/chatStore'
import { chatAPI } from '../../api/client'
import { useToast } from '../ui/Toast'

// ── Styled ────────────────────────────────────────────────
const BubbleWrapper = styled.div<{ $isUser: boolean }>`
  display: flex;
  flex-direction: column;
  align-items: ${(p) => (p.$isUser ? 'flex-end' : 'flex-start')};
  gap: 4px;
  animation: msgBounceIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
`

const Bubble = styled.div<{ $isUser: boolean; $isError: boolean; $flat: boolean }>`
  max-width: min(85%, 640px);
  padding: var(--msg-padding, 11px 15px);
  border-radius: ${(p) => (p.$flat ? 'var(--radius-sm)' : 'var(--radius-lg)')};
  border-bottom-${(p) => (p.$isUser ? 'right' : 'left')}-radius: ${(p) => (p.$flat ? 'var(--radius-sm)' : '4px')};

  background: ${(p) =>
    p.$isError
      ? 'rgba(239,68,68,0.08)'
      : p.$isUser
      ? 'var(--grad-primary)'
      : 'var(--bg-card)'};

  color: ${(p) =>
    p.$isError
      ? '#ef4444'
      : p.$isUser
      ? '#ffffff'
      : 'var(--text-primary)'};

  border: ${(p) =>
    p.$isError
      ? '1px solid rgba(239,68,68,0.25)'
      : p.$isUser
      ? 'none'
      : '1px solid var(--border)'};

  box-shadow: ${(p) =>
    p.$isUser ? 'var(--shadow-md)' : 'var(--shadow-sm)'};

  font-size: var(--font-size);
  line-height: var(--line-height);
  word-wrap: break-word;
  overflow-wrap: anywhere;
  word-break: break-word;
  hyphens: auto;
  position: relative;

  /* Better mobile spacing */
  @media (max-width: 640px) {
    max-width: 90%;
  }
`

const Timestamp = styled.span`
  font-size: 10.5px;
  color: var(--text-muted);
  padding: 0 4px;
`

const ActionsRow = styled.div`
  display: flex;
  gap: 4px;
  margin-top: 6px;
  opacity: 0;
  transition: opacity 0.18s;

  ${BubbleWrapper}:hover & { opacity: 1; }
`

const ActionBtn = styled.button<{ $active?: boolean }>`
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  border-radius: 6px;
  border: 1px solid ${(p) => (p.$active ? 'var(--primary)' : 'var(--border)')};
  background: ${(p) => (p.$active ? 'var(--bg-active)' : 'var(--bg-card)')};
  color: ${(p) => (p.$active ? 'var(--primary)' : 'var(--text-secondary)')};
  font-size: 11px;
  cursor: pointer;
  transition: all 0.15s;

  &:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--bg-hover);
    transform: translateY(-1px);
  }
  &:active { transform: translateY(0); }
`

const CodeBlockWrapper = styled.div`
  position: relative;
  margin: 0.5em 0;

  .copy-code-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    padding: 3px 8px;
    border-radius: 5px;
    border: 1px solid rgba(255,255,255,0.15);
    background: rgba(255,255,255,0.08);
    color: #cdd6f4;
    font-size: 10px;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.15s;
  }

  &:hover .copy-code-btn { opacity: 1; }
`

// ── Code block with copy button ───────────────────────────
function CodeBlock({ children, className }: { children?: React.ReactNode; className?: string }) {
  const { showToast } = useToast()
  const lang = className?.replace('language-', '') || ''

  const handleCopy = async () => {
    const text = String(children).replace(/\n$/, '')
    try {
      await navigator.clipboard.writeText(text)
      showToast('Code copied!', 'success')
    } catch {
      showToast('Copy failed', 'error')
    }
  }

  return (
    <CodeBlockWrapper>
      <pre>
        <code className={`language-${lang}`}>{children}</code>
      </pre>
      <button className="copy-code-btn" onClick={handleCopy}>
        <i className="fas fa-copy" /> Copy
      </button>
    </CodeBlockWrapper>
  )
}

// ── Component ─────────────────────────────────────────────
interface MessageBubbleProps {
  message: Message
  bubbleStyle: 'rounded' | 'flat'
  sessionId: number | null
  conversationId: number | null | undefined
  onRegenerate?: () => void
}

export default function MessageBubble({
  message,
  bubbleStyle,
  sessionId,
  conversationId,
  onRegenerate,
}: MessageBubbleProps) {
  const { showToast } = useToast()
  const [reaction, setReaction] = useState<'up' | 'down' | null>(null)
  const [thumbAnim, setThumbAnim] = useState<string | null>(null)

  const handleCopy = useCallback(async () => {
    try {
      await navigator.clipboard.writeText(message.content)
      showToast('Copied to clipboard!', 'success')
    } catch {
      showToast('Copy failed — select text manually', 'error')
    }
  }, [message.content, showToast])

  const handleReaction = useCallback(
    async (type: 'up' | 'down') => {
      const anim = `${message.id}-${type}`
      setThumbAnim(anim)
      setTimeout(() => setThumbAnim(null), 500)

      const newReaction = reaction === type ? null : type
      setReaction(newReaction)

      if (message.message_id && sessionId) {
        try {
          await chatAPI.submitReaction({
            message_id: message.message_id,
            reaction_type: type === 'up' ? 'thumbs_up' : 'thumbs_down',
            session_id: sessionId,
          })
          showToast('Thanks for your feedback!', 'success')
        } catch {
          showToast('Could not save feedback', 'error')
        }
      }
    },
    [message.id, message.message_id, reaction, sessionId, showToast]
  )

  const isUser = message.role === 'user'
  const timeStr = format(new Date(message.timestamp), 'HH:mm')

  return (
    <BubbleWrapper $isUser={isUser}>
      <Bubble $isUser={isUser} $isError={!!message.isError} $flat={bubbleStyle === 'flat'}>
        {isUser ? (
          message.content
        ) : (
          <div className="bot-markdown">
            <ReactMarkdown
              remarkPlugins={[remarkGfm]}
              components={{
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                code({ className, children, ...props }: any) {
                  const isBlock = /language-/.test(className || '')
                  if (isBlock) {
                    return <CodeBlock className={className}>{children}</CodeBlock>
                  }
                  return <code className={className} {...props}>{children}</code>
                },
              }}
            >
              {message.content}
            </ReactMarkdown>
          </div>
        )}
      </Bubble>

      {/* Timestamp */}
      <Timestamp>{timeStr}</Timestamp>

      {/* Action buttons for bot messages */}
      {!isUser && (
        <ActionsRow>
          <ActionBtn
            $active={reaction === 'up'}
            onClick={() => handleReaction('up')}
            title="Helpful"
            style={thumbAnim === `${message.id}-up` ? { animation: 'thumbPulse 0.4s ease' } : {}}
          >
            <i className="fas fa-thumbs-up" /> Helpful
          </ActionBtn>
          <ActionBtn
            $active={reaction === 'down'}
            onClick={() => handleReaction('down')}
            title="Not helpful"
            style={thumbAnim === `${message.id}-down` ? { animation: 'thumbPulse 0.4s ease' } : {}}
          >
            <i className="fas fa-thumbs-down" />
          </ActionBtn>
          {onRegenerate && (
            <ActionBtn onClick={onRegenerate} title="Regenerate">
              <i className="fas fa-rotate-right" />
            </ActionBtn>
          )}
          <ActionBtn onClick={handleCopy} title="Copy">
            <i className="fas fa-copy" />
          </ActionBtn>
        </ActionsRow>
      )}
    </BubbleWrapper>
  )
}
