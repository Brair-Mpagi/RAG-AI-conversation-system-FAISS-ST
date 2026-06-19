import styled from 'styled-components';

export const ChatRoot = styled.div`
  --primaryGradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  --secondaryGradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
  --accentGradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
  --primaryBoxShadow: 0px 20px 60px rgba(102, 126, 234, 0.4);
  --secondaryBoxShadow: 0px -10px 30px rgba(102, 126, 234, 0.15);
  --primary: #667eea;
  --secondary: #764ba2;
  --accent: #4facfe;
  --online: #10B981;
  --success: #00f2fe;
  --glass-bg: rgba(255, 255, 255, 0.95);
  --glass-border: rgba(255, 255, 255, 0.3);
  
  position: fixed;
  bottom: 30px;
  right: 30px;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
`;

export const Container = styled.div<{ isOpen: boolean; isMaximized: boolean }>`
  position: ${props => props.isMaximized ? 'fixed' : 'absolute'};
  bottom: ${props => props.isMaximized ? '2vh' : '100px'};
  right: ${props => props.isMaximized ? '2vw' : '20px'};
  width: ${props => props.isMaximized ? '94vw' : 'min(340px, 90vw)'};
  height: ${props => props.isMaximized ? '94vh' : 'auto'};
  max-height: ${props => props.isMaximized ? '94vh' : '480px'};
  background: var(--glass-bg);
  backdrop-filter: blur(20px) saturate(180%);
  -webkit-backdrop-filter: blur(20px) saturate(180%);
  border-radius: ${props => props.isMaximized ? '16px' : '20px'};
  border: 1px solid var(--glass-border);
  box-shadow: ${props => props.isMaximized
    ? '0 25px 80px rgba(102, 126, 234, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.5) inset'
    : '0 20px 70px rgba(102, 126, 234, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.5) inset'};
  overflow: hidden;
  display: ${props => props.isOpen ? 'flex' : 'none'};
  flex-direction: column;
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  transform: ${props => props.isOpen ? 'translateY(0) scale(1)' : 'translateY(20px) scale(0.95)'};
  opacity: ${props => props.isOpen ? 1 : 0};
  z-index: 1000;
`;

export const Header = styled.div`
  background: var(--primaryGradient);
  padding: 13px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top-left-radius: 20px;
  border-top-right-radius: 20px;
  box-shadow: 0 8px 32px rgba(102, 126, 234, 0.25);
  flex-shrink: 0;
  position: relative;
  
  &::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
  }
`;

export const HeaderContent = styled.div`
  display: flex;
  align-items: center;
  flex-grow: 1;
`;

export const HeaderImage = styled.div`
  margin-right: 12px;
  position: relative;
  
  img {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.9);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
    transition: transform 0.3s ease;
  }
  
  &:hover img {
    transform: scale(1.1) rotate(5deg);
  }
`;

export const HeaderText = styled.div`
  color: white;
  h4 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: -0.3px;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
  }
  p {
    margin: 2px 0 0;
    font-size: 10px;
    opacity: 0.95;
    font-weight: 400;
  }
`;

export const HeaderControls = styled.div`
  display: flex;
  align-items: center;
  margin-left: auto;
  padding-left: 16px;
`;

export const HeaderButton = styled.button`
  background: rgba(255, 255, 255, 0.15);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.25);
  color: white;
  font-size: 13px;
  cursor: pointer;
  padding: 7px;
  border-radius: 9px;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  margin-left: 5px;
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;

  &:hover {
    background: rgba(255, 255, 255, 0.25);
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
  }

  &:active {
    transform: translateY(0) scale(0.98);
  }
`;

export const MessagesContainer = styled.div<{ isMaximized: boolean }>`
  padding: 16px;
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  display: flex;
  flex-direction: column;
  background: linear-gradient(to bottom, #fafbfc 0%, #f5f7fa 100%);
  min-height: ${props => props.isMaximized ? '180px' : '260px'};
  max-height: ${props => props.isMaximized ? 'calc(94vh - 170px)' : '320px'};
  
  /* Custom scrollbar styling */
  &::-webkit-scrollbar {
    width: 8px;
  }
  
  &::-webkit-scrollbar-track {
    background: rgba(102, 126, 234, 0.05);
    border-radius: 10px;
    margin: 4px;
  }
  
  &::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
    border-radius: 10px;
    border: 2px solid transparent;
    background-clip: padding-box;
  }
  
  &::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #764ba2 0%, #667eea 100%);
    background-clip: padding-box;
  }
`;

export const Message = styled.div<{ isUser: boolean }>`
  margin: 6px 0;
  padding: 10px 14px;
  max-width: 75%;
  font-size: 13px;
  line-height: 1.45;
  word-wrap: break-word;
  align-self: ${props => props.isUser ? 'flex-end' : 'flex-start'};
  background: ${props => props.isUser
    ? 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'
    : 'rgba(255, 255, 255, 0.9)'};
  color: ${props => props.isUser ? 'white' : '#2d3748'};
  border-radius: 16px;
  border-bottom-${props => props.isUser ? 'right' : 'left'}-radius: 5px;
  box-shadow: ${props => props.isUser
    ? '0 4px 15px rgba(102, 126, 234, 0.3)'
    : '0 2px 10px rgba(0, 0, 0, 0.08)'};
  border: ${props => props.isUser ? 'none' : '1px solid rgba(0, 0, 0, 0.05)'};
  backdrop-filter: ${props => props.isUser ? 'none' : 'blur(10px)'};
  animation: messageSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
  position: relative;
  
  &::before {
    content: '';
    position: absolute;
    ${props => props.isUser ? 'right: -5px' : 'left: -5px'};
    bottom: 8px;
    width: 0;
    height: 0;
    border-style: solid;
    border-width: ${props => props.isUser ? '0 10px 10px 0' : '0 0 10px 10px'};
    border-color: ${props => props.isUser
    ? 'transparent #764ba2 transparent transparent'
    : 'transparent transparent rgba(255, 255, 255, 0.9) transparent'};
  }

  @keyframes messageSlideIn {
    from {
      transform: translateY(15px) scale(0.95);
      opacity: 0;
    }
    to {
      transform: translateY(0) scale(1);
      opacity: 1;
    }
  }
`;

export const Footer = styled.div`
  padding: 14px 16px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  box-shadow: 0 -8px 32px rgba(102, 126, 234, 0.2);
  border-bottom-right-radius: 20px;
  border-bottom-left-radius: 20px;
  flex-shrink: 0;
  position: relative;
  
  &::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
  }
`;

export const Input = styled.input`
  width: 100%;
  padding: 10px 50px 10px 14px;
  border: none;
  border-radius: 22px;
  outline: none;
  font-size: 13px;
  box-sizing: border-box;
  background: rgba(255, 255, 255, 0.95);
  color: #1a202c;
  backdrop-filter: blur(10px);
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1), inset 0 2px 4px rgba(0, 0, 0, 0.05);
  transition: all 0.3s ease;
  font-family: inherit;
  
  &::placeholder {
    color: #718096;
    font-size: 12px;
  }
  
  &:focus {
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.25), inset 0 2px 4px rgba(0, 0, 0, 0.05);
    transform: translateY(-1px);
  }
  
  &:disabled {
    opacity: 0.7;
    cursor: not-allowed;
  }
`;

export const SendButton = styled.button`
  background: rgba(255, 255, 255, 0.2);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.3);
  color: white;
  font-weight: 600;
  cursor: pointer;
  padding: 7px 10px;
  font-size: 15px;
  border-radius: 18px;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 34px;
  height: 34px;
  
  &:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.3);
    transform: scale(1.1) rotate(15deg);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
  }
  
  &:active:not(:disabled) {
    transform: scale(0.95);
  }
  
  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
`;

export const ToggleButton = styled.button`
  position: fixed;
  bottom: 30px;
  right: 30px;
  width: 58px;
  height: 58px;
  padding: 0;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border: 2px solid rgba(255, 255, 255, 0.8);
  border-radius: 50%;
  box-shadow: 0 10px 40px rgba(102, 126, 234, 0.4), 0 0 0 0 rgba(102, 126, 234, 0.5);
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  display: flex;
  align-items: center;
  justify-content: center;
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  
  &:hover {
    transform: scale(1.15) rotate(5deg);
    box-shadow: 0 15px 50px rgba(102, 126, 234, 0.5), 0 0 0 8px rgba(102, 126, 234, 0.1);
    animation: none;
  }
  
  &:active {
    transform: scale(0.95);
  }
  
  img {
    width: 28px;
    height: 28px;
    filter: brightness(0) invert(1);
  }
  
  @keyframes pulse {
    0%, 100% {
      box-shadow: 0 10px 40px rgba(102, 126, 234, 0.4), 0 0 0 0 rgba(102, 126, 234, 0.5);
    }
    50% {
      box-shadow: 0 10px 40px rgba(102, 126, 234, 0.4), 0 0 0 12px rgba(102, 126, 234, 0);
    }
  }
`;

export const OnlineIndicator = styled.span`
  position: absolute;
  bottom: 40px;
  right: 35px;
  width: 15px;
  height: 15px;
  background: var(--online);
  border-radius: 50%;
  border: 2px solid white;
`;

export const MessageFeedbackControls = styled.div`
  display: flex;
  gap: 6px;
  margin-top: 8px;
  opacity: 0.7;
  transition: opacity 0.2s ease;
  
  &:hover {
    opacity: 1;
  }
`;

export const FeedbackButton = styled.button<{ active?: boolean }>`
  background: ${props => props.active ? 'rgba(102, 126, 234, 0.15)' : 'transparent'};
  border: 1px solid rgba(0, 0, 0, 0.1);
  color: ${props => props.active ? '#667eea' : '#64748b'};
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
  transition: all 0.2s ease;
  display: flex;
  align-items: center;
  gap: 4px;
  
  &:hover:not(:disabled) {
    background: rgba(102, 126, 234, 0.1);
    transform: translateY(-1px);
    color: #667eea;
  }
  
  &:active:not(:disabled) {
    transform: translateY(0);
  }
  
  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
  
  i {
    font-size: 11px;
  }
`;

export const SuggestedQuestionBtn = styled.button`
  background: rgba(102,126,234,0.10);
  border: 1px solid rgba(102,126,234,0.30);
  border-radius: 20px;
  color: #4c51bf;
  font-size: 0.75rem;
  font-weight: 500;
  padding: 5px 12px;
  cursor: pointer;
  transition: all 0.2s ease;
  white-space: nowrap;
  line-height: 1.4;

  &:hover:not(:disabled) {
    background: rgba(102,126,234,0.22);
    transform: translateY(-1px);
  }

  &:disabled {
    cursor: not-allowed;
  }

  /* Hide the 1st and 5th items on small mobile screens */
  @media (max-width: 480px) {
    &:nth-child(1),
    &:nth-child(5) {
      display: none;
    }
  }
`;