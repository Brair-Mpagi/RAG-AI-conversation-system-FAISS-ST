import { useState } from 'react'
import styled from 'styled-components'
import { chatAPI } from '../../api/client'
import { useToast } from '../ui/Toast'

const Overlay = styled.div<{ $visible: boolean }>`
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  backdrop-filter: blur(4px);
  z-index: 9000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  opacity: ${(p) => (p.$visible ? 1 : 0)};
  pointer-events: ${(p) => (p.$visible ? 'auto' : 'none')};
  transition: opacity 0.22s;
`

const Modal = styled.div<{ $visible: boolean }>`
  background: var(--bg-card);
  border-radius: var(--radius-xl);
  border: 1px solid var(--border);
  box-shadow: var(--shadow-xl);
  width: 100%;
  max-width: 420px;
  padding: 28px;
  transform: ${(p) => (p.$visible ? 'translateY(0) scale(1)' : 'translateY(24px) scale(0.96)')};
  transition: transform 0.28s cubic-bezier(0.34,1.56,0.64,1);
`

const ModalHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
`

const ModalTitle = styled.h2`
  font-size: 18px;
  font-weight: 800;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 10px;
  i { color: var(--primary); font-size: 16px; }
`

const CloseBtn = styled.button`
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--bg-hover);
  color: var(--text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 13px;
  transition: all var(--transition);
  &:hover { background: var(--bg-active); color: var(--text-primary); }
`

const Field = styled.div`
  margin-bottom: 14px;
  label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
`

const Input = styled.input`
  width: 100%;
  padding: 10px 14px;
  border-radius: var(--radius-md);
  border: 1.5px solid var(--border);
  background: var(--bg-input);
  color: var(--text-primary);
  font-size: 13px;
  font-family: var(--font);
  outline: none;
  transition: border-color var(--transition);
  box-sizing: border-box;
  &:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
  &::placeholder { color: var(--text-muted); }
`

const Textarea = styled.textarea`
  width: 100%;
  padding: 10px 14px;
  border-radius: var(--radius-md);
  border: 1.5px solid var(--border);
  background: var(--bg-input);
  color: var(--text-primary);
  font-size: 13px;
  font-family: var(--font);
  outline: none;
  resize: vertical;
  min-height: 90px;
  transition: border-color var(--transition);
  box-sizing: border-box;
  &:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
  &::placeholder { color: var(--text-muted); }
`

const SubmitBtn = styled.button<{ $loading: boolean }>`
  width: 100%;
  padding: 12px;
  border-radius: var(--radius-md);
  border: none;
  background: var(--grad-primary);
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  cursor: ${(p) => (p.$loading ? 'not-allowed' : 'pointer')};
  opacity: ${(p) => (p.$loading ? 0.7 : 1)};
  margin-top: 6px;
  transition: all var(--transition);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  box-shadow: var(--shadow-md);
  &:hover:not(:disabled) { transform: translateY(-1px); box-shadow: var(--shadow-lg); }
  &:active:not(:disabled) { transform: translateY(0); }
`

const InfoNote = styled.p`
  font-size: 12px;
  color: var(--text-muted);
  margin-bottom: 18px;
  line-height: 1.5;
  background: var(--bg-hover);
  padding: 10px 14px;
  border-radius: var(--radius-md);
  border-left: 3px solid var(--primary);
`

interface Props {
  isVisible: boolean
  onClose: () => void
  sessionId: number | null
  conversationId: number | null | undefined
}

export default function EscalateModal({ isVisible, onClose, sessionId, conversationId }: Props) {
  const { showToast } = useToast()
  const [name, setName]       = useState('')
  const [email, setEmail]     = useState('')
  const [query, setQuery]     = useState('')
  const [loading, setLoading] = useState(false)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!query.trim()) { showToast('Please describe your question', 'error'); return }
    setLoading(true)
    try {
      await chatAPI.forwardQuery({
        username: name || undefined,
        email: email || undefined,
        query: query.trim(),
        session_id: sessionId ?? undefined,
        conversation_id: conversationId ?? undefined,
        interface_type: 'web',
      })
      showToast('Query forwarded to MMU staff!', 'success')
      setName(''); setEmail(''); setQuery('')
      onClose()
    } catch {
      showToast('Failed to forward query — please try again', 'error')
    } finally {
      setLoading(false)
    }
  }

  return (
    <Overlay $visible={isVisible} onClick={(e) => e.target === e.currentTarget && onClose()}>
      <Modal $visible={isVisible}>
        <ModalHeader>
          <ModalTitle><i className="fas fa-life-ring" /> Contact Support</ModalTitle>
          <CloseBtn onClick={onClose}><i className="fas fa-times" /></CloseBtn>
        </ModalHeader>

        <InfoNote>
          Can't find what you need? Our team will get back to you as soon as possible.
          You can also reach MMU directly at <strong>info@mmu.ac.ug</strong>.
        </InfoNote>

        <form onSubmit={handleSubmit}>
          <Field>
            <label>Your name (optional)</label>
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. John Doe" />
          </Field>
          <Field>
            <label>Email (for a reply)</label>
            <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@example.com" />
          </Field>
          <Field>
            <label>Your question *</label>
            <Textarea value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Describe what you'd like to know…" required />
          </Field>
          <SubmitBtn type="submit" $loading={loading} disabled={loading}>
            {loading ? <><i className="fas fa-spinner" style={{ animation: 'spin 0.8s linear infinite' }} /> Sending…</> : <><i className="fas fa-paper-plane" /> Send to MMU</>}
          </SubmitBtn>
        </form>
      </Modal>
    </Overlay>
  )
}
