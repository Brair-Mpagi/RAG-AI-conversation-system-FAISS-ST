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
  max-width: 440px;
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
  i { color: var(--primary); }
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

const StarRow = styled.div`
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
`

const Star = styled.button<{ $active: boolean }>`
  font-size: 24px;
  background: none;
  border: none;
  cursor: pointer;
  color: ${(p) => (p.$active ? '#f59e0b' : 'var(--border)')};
  transition: transform 0.15s, color 0.15s;
  &:hover { transform: scale(1.2); color: #f59e0b; }
`

const ChipGrid = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
`

const Chip = styled.button<{ $active: boolean }>`
  padding: 6px 14px;
  border-radius: var(--radius-full);
  border: 1.5px solid ${(p) => (p.$active ? 'var(--primary)' : 'var(--border)')};
  background: ${(p) => (p.$active ? 'var(--bg-active)' : 'transparent')};
  color: ${(p) => (p.$active ? 'var(--primary)' : 'var(--text-secondary)')};
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all var(--transition);
  &:hover { border-color: var(--primary); color: var(--primary); }
`

const CommentArea = styled.textarea`
  width: 100%;
  padding: 10px 14px;
  border-radius: var(--radius-md);
  border: 1.5px solid var(--border);
  background: var(--bg-input);
  color: var(--text-primary);
  font-family: var(--font);
  font-size: 13px;
  resize: vertical;
  min-height: 80px;
  outline: none;
  margin-bottom: 16px;
  box-sizing: border-box;
  transition: border-color var(--transition);
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
  transition: all var(--transition);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  box-shadow: var(--shadow-md);
  &:hover:not(:disabled) { transform: translateY(-1px); box-shadow: var(--shadow-lg); }
`

const SectionLabel = styled.p`
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-secondary);
  margin-bottom: 8px;
`

const CATEGORIES = ['Wrong answer', 'Incomplete', 'Offensive', 'Too slow', 'Other']
const RATINGS = ['1', '2', '3', '4', '5']

interface Props {
  isVisible: boolean
  onClose: () => void
  messageId: number | null | undefined
  conversationId: number | null | undefined
  sessionId: number | null
}

export default function FeedbackModal({ isVisible, onClose, messageId, conversationId, sessionId }: Props) {
  const { showToast } = useToast()
  const [rating, setRating]     = useState(0)
  const [category, setCategory] = useState('')
  const [comment, setComment]   = useState('')
  const [loading, setLoading]   = useState(false)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!messageId || !conversationId || !sessionId) {
      showToast('Missing context for feedback', 'error'); return
    }
    if (!category) { showToast('Please select a category', 'error'); return }
    setLoading(true)
    try {
      await chatAPI.submitDetailedFeedback({
        message_id: messageId,
        conversation_id: conversationId,
        session_id: sessionId,
        category,
        comment: comment.trim(),
        rating: String(rating || 3),
      })
      showToast('Feedback submitted — thank you!', 'success')
      setRating(0); setCategory(''); setComment('')
      onClose()
    } catch {
      showToast('Could not submit feedback — please try again', 'error')
    } finally {
      setLoading(false)
    }
  }

  return (
    <Overlay $visible={isVisible} onClick={(e) => e.target === e.currentTarget && onClose()}>
      <Modal $visible={isVisible}>
        <ModalHeader>
          <ModalTitle><i className="fas fa-comment-dots" /> Rate this response</ModalTitle>
          <CloseBtn onClick={onClose}><i className="fas fa-times" /></CloseBtn>
        </ModalHeader>

        <form onSubmit={handleSubmit}>
          <SectionLabel>Overall rating</SectionLabel>
          <StarRow>
            {RATINGS.map((r) => (
              <Star key={r} $active={parseInt(r) <= rating} onClick={() => setRating(parseInt(r))} type="button">
                ★
              </Star>
            ))}
          </StarRow>

          <SectionLabel>What went wrong?</SectionLabel>
          <ChipGrid>
            {CATEGORIES.map((c) => (
              <Chip key={c} $active={category === c} onClick={() => setCategory(c)} type="button">
                {c}
              </Chip>
            ))}
          </ChipGrid>

          <SectionLabel>Additional comments (optional)</SectionLabel>
          <CommentArea
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            placeholder="Tell us more about what happened…"
          />

          <SubmitBtn type="submit" $loading={loading} disabled={loading}>
            {loading
              ? <><i className="fas fa-spinner" style={{ animation: 'spin 0.8s linear infinite' }} /> Submitting…</>
              : <><i className="fas fa-paper-plane" /> Submit feedback</>
            }
          </SubmitBtn>
        </form>
      </Modal>
    </Overlay>
  )
}
