import { useState } from 'react';
import { forwardQuery } from '../api/client';
import {
  PopupContainer,
  PopupOverlay,
  PopupHeader,
  CloseButton,
  FormGroup,
  InputIconWrapper,
  InputIcon,
  FormControl,
  FormTextArea,
  SubmitButton,
  FooterText
} from './EscalateForm.styles';

interface EscalateFormProps {
  isVisible: boolean;
  onClose: () => void;
  sessionId?: number | null;
  conversationId?: number | null;
  /** Pass Chat's showToast so we avoid browser alert() calls */
  onToast?: (message: string, type?: 'success' | 'error' | 'warning') => void;
}

export default function EscalateForm({ isVisible, onClose, sessionId, conversationId, onToast }: EscalateFormProps) {
  const [formData, setFormData] = useState({ username: '', email: '', query: '' });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [emailError, setEmailError] = useState('');

  // Use toast if available, otherwise fall back to console (never alert)
  const notify = (message: string, type: 'success' | 'error' | 'warning' = 'error') => {
    if (onToast) {
      onToast(message, type);
    } else {
      console.warn('[EscalateForm]', message);
    }
  };

  const validateEmail = (email: string) => {
    if (!email) { setEmailError(''); return; }
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    setEmailError(emailRegex.test(email) ? '' : 'Please enter a valid email address');
  };

  const handleSubmit = async () => {
    if (!formData.username.trim()) { notify('Please enter your name.', 'error'); return; }
    if (!formData.email.trim()) { notify('Please enter your email address.', 'error'); return; }
    if (emailError) { notify('Please enter a valid email address.', 'error'); return; }
    if (!formData.query.trim()) { notify('Please describe your query.', 'error'); return; }

    setIsSubmitting(true);
    try {
      const res = await forwardQuery({
        username: formData.username,
        email: formData.email,
        query: formData.query,
        session_id: sessionId ?? undefined,
        conversation_id: conversationId ?? undefined,
        interface_type: 'web',
      });

      if (res?.status === 'ok') {
        notify('Your query has been forwarded! We\'ll get back to you within 24\u201348 hours.', 'success');
        setFormData({ username: '', email: '', query: '' });
        onClose();
      } else {
        throw new Error('Unexpected response from server');
      }
    } catch (error) {
      console.error('EscalateForm submit error:', error);
      notify('Failed to submit your query. Please try again later.', 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <>
      <PopupOverlay isVisible={isVisible} onClick={onClose} />
      <PopupContainer isVisible={isVisible}>
        <PopupHeader>
          <h2>Forward Your Inquiry</h2>
          <p>Fill in the details to forward your inquiry to our team</p>
          <CloseButton onClick={onClose}>
            <i className="fas fa-times"></i>
          </CloseButton>
        </PopupHeader>

        <FormGroup>
          <label htmlFor="escalate-username">Name</label>
          <InputIconWrapper>
            <FormControl
              id="escalate-username"
              type="text"
              placeholder="Enter your name"
              value={formData.username}
              onChange={(e) => setFormData(prev => ({ ...prev, username: e.target.value }))}
            />
            <InputIcon className="fas fa-user" />
          </InputIconWrapper>
        </FormGroup>

        <FormGroup>
          <label htmlFor="escalate-email">Email</label>
          <InputIconWrapper>
            <FormControl
              id="escalate-email"
              type="email"
              placeholder="Enter your email"
              value={formData.email}
              onChange={(e) => { setFormData(prev => ({ ...prev, email: e.target.value })); validateEmail(e.target.value); }}
              style={emailError ? { borderColor: '#ef4444', boxShadow: '0 0 0 3px rgba(239,68,68,0.15)' } : {}}
            />
            <InputIcon className="fas fa-envelope" />
          </InputIconWrapper>
          {emailError && (
            <span style={{ color: '#ef4444', fontSize: '0.75rem', marginTop: '4px', display: 'block' }}>
              {emailError}
            </span>
          )}
        </FormGroup>

        <FormGroup>
          <label htmlFor="escalate-query">Your Inquiry</label>
          <FormTextArea
            id="escalate-query"
            placeholder="Describe your query in detail…"
            value={formData.query}
            onChange={(e) => setFormData(prev => ({ ...prev, query: e.target.value }))}
          />
        </FormGroup>

        <SubmitButton
          onClick={handleSubmit}
          disabled={isSubmitting || !!emailError || !formData.email || !formData.username || !formData.query}
        >
          {isSubmitting ? (
            <><i className="fas fa-spinner fa-spin icon"></i>Submitting…</>
          ) : (
            <><i className="fas fa-paper-plane icon"></i>Submit Question</>
          )}
        </SubmitButton>

        <FooterText>We'll get back to you within 24–48 hours</FooterText>
      </PopupContainer>
    </>
  );
}