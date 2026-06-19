import { useState, useRef, useEffect } from 'react'
import styled from 'styled-components'
import { useChatStore } from '../../store/chatStore'

const MAX_LEN  = 5000
const WARN_LEN = 4500

const InputBar = styled.div`
  padding: 12px 16px;
  padding-bottom: calc(12px + env(safe-area-inset-bottom));
  background: var(--bg-sidebar);
  border-top: 1px solid var(--border);
  flex-shrink: 0;

  @media (max-width: 640px) {
    padding: 10px 14px;
    padding-bottom: calc(10px + env(safe-area-inset-bottom));
  }
`

const InputRow = styled.div`
  display: flex;
  align-items: flex-end;
  gap: 10px;
  background: var(--bg-input);
  border: 1.5px solid var(--border);
  border-radius: var(--radius-xl);
  padding: 10px 10px 10px 16px;
  transition: border-color var(--transition), box-shadow var(--transition);
  box-shadow: var(--shadow-sm);

  &:focus-within {
    border-color: var(--border-focus);
    box-shadow: 0 0 0 3px rgba(102,126,234,0.12);
  }
`

const Textarea = styled.textarea`
  flex: 1;
  border: none;
  background: transparent;
  outline: none;
  resize: none;
  font-family: var(--font);
  font-size: var(--font-size);
  color: var(--text-primary);
  line-height: 1.5;
  max-height: 160px;
  overflow-y: auto;
  min-height: 22px;
  /* Better mobile input handling */
  -webkit-appearance: none;
  touch-action: manipulation;

  &::placeholder { color: var(--text-muted); }
  &:disabled { cursor: not-allowed; opacity: 0.7; }

  &::-webkit-scrollbar { width: 4px; }
  &::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
`

const SendBtn = styled.button<{ $disabled: boolean }>`
  width: 38px;
  height: 38px;
  border-radius: 50%;
  border: none;
  background: ${(p) => (p.$disabled ? 'var(--bg-hover)' : 'var(--grad-primary)')};
  color: ${(p) => (p.$disabled ? 'var(--text-muted)' : '#fff')};
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  cursor: ${(p) => (p.$disabled ? 'not-allowed' : 'pointer')};
  flex-shrink: 0;
  transition: all var(--transition);
  box-shadow: ${(p) => (p.$disabled ? 'none' : 'var(--shadow-md)')};
  /* Better mobile touch target */
  touch-action: manipulation;

  &:hover:not(:disabled) { transform: scale(1.08); box-shadow: var(--shadow-lg); }
  &:active:not(:disabled) { transform: scale(0.96); }

  @media (max-width: 640px) {
    width: 42px;
    height: 42px;
    font-size: 15px;
  }
`

const SubRow = styled.div`
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 8px;
  padding: 0 4px;
`

const EscalateBtn = styled.button`
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: var(--radius-full);
  border: 1px solid var(--border);
  background: transparent;
  color: var(--text-secondary);
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  transition: all var(--transition);

  &:hover { border-color: var(--primary); color: var(--primary); background: var(--bg-hover); }
`

const CharCounter = styled.span<{ $warn: boolean; $limit: boolean }>`
  font-size: 11px;
  font-weight: 500;
  color: ${(p) => (p.$limit ? '#ef4444' : p.$warn ? '#f59e0b' : 'var(--text-muted)')};
`

const RateLimitBadge = styled.div`
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #f59e0b;
  font-weight: 500;
  padding: 4px 10px;
  border-radius: var(--radius-full);
  background: rgba(245,158,11,0.1);
  border: 1px solid rgba(245,158,11,0.25);
`

interface ChatInputProps {
  onSend: (text: string) => void
  onEscalate: () => void
  disabled?: boolean
  isRateLimited: boolean
  rateLimitResetsAt: number | null
}

export default function ChatInput({ onSend, onEscalate, disabled, isRateLimited, rateLimitResetsAt }: ChatInputProps) {
  const [value, setValue] = useState('')
  const textareaRef = useRef<HTMLTextAreaElement>(null)
  const { activeConversationId, messages } = useChatStore()

  // Auto-focus when switching to an empty conversation (e.g., New Chat)
  useEffect(() => {
    if (activeConversationId) {
      const msgs = messages[activeConversationId]
      if (!msgs || msgs.length === 0) {
        // Slight delay to ensure DOM is ready and drawer is closed
        setTimeout(() => textareaRef.current?.focus(), 100)
      }
    }
  }, [activeConversationId])

  // Auto-resize textarea
  useEffect(() => {
    const el = textareaRef.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = Math.min(el.scrollHeight, 160) + 'px'
  }, [value])

  const handleSend = () => {
    const trimmed = value.trim()
    if (!trimmed || disabled || isRateLimited || value.length > MAX_LEN) return
    onSend(trimmed)
    setValue('')
    if (textareaRef.current) textareaRef.current.style.height = 'auto'
  }

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  const len      = value.length
  const isWarn   = len >= WARN_LEN
  const isAtLim  = len >= MAX_LEN
  const canSend  = !disabled && !isRateLimited && !!value.trim() && !isAtLim

  // Countdown timer
  const [countdown, setCountdown] = useState<number | null>(null)
  useEffect(() => {
    if (!rateLimitResetsAt) { setCountdown(null); return }
    const update = () => {
      const secs = Math.ceil((rateLimitResetsAt - Date.now()) / 1000)
      if (secs <= 0) { setCountdown(null); return }
      setCountdown(secs)
    }
    update()
    const id = setInterval(update, 1000)
    return () => clearInterval(id)
  }, [rateLimitResetsAt])

  const placeholder = isRateLimited
    ? `Rate limited — please wait…`
    : disabled
    ? 'Waiting for response…'
    : 'Ask about MMU'

  return (
    <InputBar>
      <InputRow>
        <Textarea
          ref={textareaRef}
          value={value}
          onChange={(e) => setValue(e.target.value.slice(0, MAX_LEN))}
          onKeyDown={handleKeyDown}
          placeholder={placeholder}
          disabled={disabled || isRateLimited}
          rows={1}
        />
        <SendBtn
          $disabled={!canSend}
          onClick={handleSend}
          disabled={!canSend}
          title="Send message"
        >
          {disabled
            ? <i className="fas fa-spinner" style={{ animation: 'spin 0.8s linear infinite' }} />
            : <i className="fas fa-paper-plane" />
          }
        </SendBtn>
      </InputRow>

      <SubRow>
        <EscalateBtn onClick={onEscalate}>
          <i className="fas fa-life-ring" />
          <span>Need help? Contact us</span>
        </EscalateBtn>

        {isRateLimited && countdown !== null ? (
          <RateLimitBadge>
            <i className="fas fa-clock" />
            Wait {countdown}s
          </RateLimitBadge>
        ) : isWarn ? (
          <CharCounter $warn={isWarn} $limit={isAtLim}>
            {len} / {MAX_LEN}
          </CharCounter>
        ) : null}
      </SubRow>
    </InputBar>
  )
}
