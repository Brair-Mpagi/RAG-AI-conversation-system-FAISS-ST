import styled, { keyframes } from 'styled-components'

const bounce = keyframes`
  0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
  30%           { transform: translateY(-6px); opacity: 1; }
`

const Wrapper = styled.div`
  display: flex;
  align-items: center;
  gap: 8px;
  padding: var(--msg-padding, 11px 15px);
  background: var(--bg-card);
  border-radius: var(--radius-lg);
  border-bottom-left-radius: 4px;
  border: 1px solid var(--border);
  box-shadow: var(--shadow-sm);
  align-self: flex-start;
  animation: msgBounceIn 0.35s cubic-bezier(0.34,1.56,0.64,1);
`

const Dot = styled.span<{ $delay: number }>`
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--primary);
  animation: ${bounce} 1.1s ease-in-out infinite;
  animation-delay: ${(p) => p.$delay}s;
`

const Label = styled.span`
  font-size: 12px;
  color: var(--text-muted);
  font-style: italic;
`

export default function TypingIndicator() {
  return (
    <Wrapper>
      <Dot $delay={0} />
      <Dot $delay={0.18} />
      <Dot $delay={0.36} />
      <Label>Thinking…</Label>
    </Wrapper>
  )
}
