import { NavLink, useNavigate } from 'react-router-dom'
import styled from 'styled-components'
import { useChatStore } from '../../store/chatStore'

const Nav = styled.nav`
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  height: 56px;
  padding-bottom: env(safe-area-inset-bottom);
  background: var(--bg-sidebar);
  border-top: 1px solid var(--border);
  display: flex;
  align-items: stretch;
  z-index: 200;
  box-shadow: 0 -4px 20px rgba(0,0,0,0.06);
`

const NavBtn = styled(NavLink)`
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  color: var(--text-muted);
  font-size: 10px;
  font-weight: 600;
  text-decoration: none;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  
  i { 
    font-size: 18px;
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  &.active {
    color: var(--primary);
    i { transform: scale(1.1); }
  }
  
  &:active {
    transform: scale(0.95);
  }
`

const NewChatNavBtn = styled.button`
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  color: var(--text-muted);
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  border: none;
  background: none;
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  
  i { 
    font-size: 18px;
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  &:hover { 
    color: var(--primary);
  }
  
  &:active {
    transform: scale(0.95);
    i { transform: scale(1.2); }
  }
`

export default function MobileNav() {
  const { newConversation, setActiveConversation } = useChatStore()
  const navigate = useNavigate()

  const handleNewChat = () => {
    const id = newConversation()
    setActiveConversation(id)
    // Navigate to home to show the new chat
    navigate('/')
  }

  return (
    <Nav>
      <NavBtn to="/" end>
        <i className="fas fa-comment-dots" />
        Chat
      </NavBtn>
      <NewChatNavBtn onClick={handleNewChat}>
        <i className="fas fa-plus-circle" />
        New
      </NewChatNavBtn>
      <NavBtn to="/settings">
        <i className="fas fa-cog" />
        Settings
      </NavBtn>
    </Nav>
  )
}
