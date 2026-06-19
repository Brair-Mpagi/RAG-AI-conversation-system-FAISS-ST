import styled from 'styled-components';

export const PopupContainer = styled.div<{ isVisible: boolean }>`
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.98) 100%);
  backdrop-filter: blur(25px) saturate(180%);
  -webkit-backdrop-filter: blur(25px) saturate(180%);
  border-radius: 20px;
  width: 88%;
  max-width: 400px;
  max-height: 85vh;
  overflow-y: auto;
  padding: 24px 22px;
  box-shadow: 0 25px 80px rgba(102, 126, 234, 0.3), 
              0 10px 40px rgba(0, 0, 0, 0.12),
              0 0 0 1px rgba(255, 255, 255, 0.8) inset;
  display: ${props => props.isVisible ? 'block' : 'none'};
  animation: popupSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
  z-index: 1100;
  border: 2px solid rgba(255, 255, 255, 0.6);

  @keyframes popupSlideIn {
    from {
      opacity: 0;
      transform: translate(-50%, -45%) scale(0.9);
    }
    to {
      opacity: 1;
      transform: translate(-50%, -50%) scale(1);
    }
  }
  
  /* Custom scrollbar for form */
  &::-webkit-scrollbar {
    width: 6px;
  }
  
  &::-webkit-scrollbar-track {
    background: rgba(102, 126, 234, 0.05);
    border-radius: 10px;
  }
  
  &::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
    border-radius: 10px;
  }
`;

export const PopupOverlay = styled.div<{ isVisible: boolean }>`
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: radial-gradient(circle at center, rgba(102, 126, 234, 0.3) 0%, rgba(0, 0, 0, 0.6) 100%);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  display: ${props => props.isVisible ? 'block' : 'none'};
  animation: overlayFadeIn 0.3s ease-out;
  z-index: 1050;
  
  @keyframes overlayFadeIn {
    from {
      opacity: 0;
    }
    to {
      opacity: 1;
    }
  }
`;

export const PopupHeader = styled.div`
  margin-bottom: 24px;
  position: relative;
  text-align: center;

  h2 {
    font-size: 1.25rem;
    font-weight: 700;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 6px;
    letter-spacing: -0.4px;
    text-shadow: 0 2px 20px rgba(102, 126, 234, 0.2);
  }

  p {
    color: #64748b;
    font-size: 0.8125rem;
    margin: 0;
    font-weight: 400;
    line-height: 1.4;
  }
`;

export const CloseButton = styled.button`
  position: absolute;
  top: -12px;
  right: -12px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border: 2px solid white;
  font-size: 1.125rem;
  cursor: pointer;
  color: white;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);

  &:hover {
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
    transform: scale(1.15) rotate(90deg);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
  }
  
  &:active {
    transform: scale(0.95);
  }
`;

export const FormGroup = styled.div`
  margin-bottom: 16px;

  label {
    display: block;
    margin-bottom: 7px;
    font-weight: 600;
    font-size: 0.8125rem;
    color: #334155;
    letter-spacing: -0.2px;
  }
`;

export const InputIconWrapper = styled.div`
  position: relative;
`;

export const InputIcon = styled.i`
  position: absolute;
  left: 13px;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  font-size: 0.9375rem;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  pointer-events: none;
`;

export const FormControl = styled.input`
  width: 100%;
  padding: 11px 14px 11px 40px;
  border: 2px solid #e2e8f0;
  border-radius: 12px;
  font-size: 0.875rem;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  background: white;
  color: #1e293b;
  box-sizing: border-box;
  font-family: inherit;

  &::placeholder {
    color: #94a3b8;
    opacity: 1;
    font-size: 0.8125rem;
  }

  &:hover {
    border-color: #cbd5e1;
  }

  &:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.12), 0 4px 12px rgba(102, 126, 234, 0.15);
    transform: translateY(-1px);
  }

  &:focus + ${InputIcon} {
    color: #667eea;
    transform: translateY(-50%) scale(1.1);
  }
`;

export const FormTextArea = styled(FormControl).attrs({ as: 'textarea' })`
  min-height: 100px;
  max-height: 200px;
  resize: vertical;
  padding: 11px 14px;
  line-height: 1.5;
  font-family: inherit;
`;

export const SubmitButton = styled.button`
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border: none;
  border-radius: 12px;
  padding: 12px 24px;
  font-size: 0.9375rem;
  font-weight: 700;
  letter-spacing: -0.2px;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  width: 100%;
  margin-top: 8px;
  box-shadow: 0 6px 24px rgba(102, 126, 234, 0.35);
  position: relative;
  overflow: hidden;
  
  &::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    transition: left 0.5s;
  }
  
  &:hover::before {
    left: 100%;
  }
  
  &:hover:not(:disabled) {
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 12px 32px rgba(102, 126, 234, 0.45);
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
  }

  &:active:not(:disabled) {
    transform: translateY(0) scale(0.98);
  }
  
  &:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
  }

  .icon {
    margin-right: 7px;
    font-size: 0.9375rem;
  }
`;

export const FooterText = styled.p`
  text-align: center;
  color: #64748b;
  font-size: 0.75rem;
  margin-top: 16px;
  margin-bottom: 0;
  position: relative;
  padding-top: 14px;
  font-weight: 400;
  line-height: 1.4;

  &::before {
    content: '';
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 80px;
    height: 2px;
    background: linear-gradient(90deg, transparent, #667eea, transparent);
    border-radius: 2px;
  }
`;