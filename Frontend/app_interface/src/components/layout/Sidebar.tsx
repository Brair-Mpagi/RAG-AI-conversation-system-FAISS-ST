import styled from 'styled-components'
import { useChatStore } from '../../store/chatStore'
import { formatDistanceToNow } from 'date-fns'
import type { AppSettings } from '../../hooks/useSettings'
import { Link, useNavigate } from 'react-router-dom'

// ── Styled ────────────────────────────────────────────────
const SidebarRoot = styled.aside<{ $collapsed: boolean }>`
  width: ${(p) => (p.$collapsed ? 'var(--sidebar-w-col)' : 'var(--sidebar-w)')};
  min-width: ${(p) => (p.$collapsed ? 'var(--sidebar-w-col)' : 'var(--sidebar-w)')};
  height: 100%;
  background: var(--bg-sidebar);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  transition: width var(--transition), min-width var(--transition);
  overflow: hidden;
  position: relative;
  z-index: 10;
`

const SidebarHeader = styled.div`
  padding: 16px 14px 12px;
  display: flex;
  align-items: center;
  gap: 10px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
`

const Logo = styled.div`
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: var(--grad-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 16px;
  flex-shrink: 0;
  box-shadow: var(--shadow-md);
`

const LogoText = styled.div`
  overflow: hidden;
  white-space: nowrap;
  h2 {
    font-size: 14px;
    font-weight: 800;
    background: var(--grad-primary);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    letter-spacing: -0.3px;
  }
  p {
    font-size: 10px;
    color: var(--text-muted);
    margin-top: 1px;
  }
`

const NewChatBtn = styled.button`
  margin: 12px 10px 8px;
  padding: 9px 14px;
  border-radius: var(--radius-md);
  background: var(--grad-primary);
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  border: none;
  box-shadow: var(--shadow-md);
  transition: all var(--transition);
  white-space: nowrap;
  overflow: hidden;
  justify-content: center;
  &:hover { transform: translateY(-1px); box-shadow: var(--shadow-lg); }
  &:active { transform: translateY(0); }
`

const SectionLabel = styled.div`
  padding: 6px 14px 4px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: var(--text-muted);
  white-space: nowrap;
  overflow: hidden;
`

const ConvList = styled.div`
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 4px 8px;
  display: flex;
  flex-direction: column;
  gap: 2px;
`

const ConvItem = styled.button<{ $active: boolean }>`
  width: 100%;
  text-align: left;
  padding: 9px 10px;
  border-radius: var(--radius-sm);
  border: none;
  background: ${(p) => (p.$active ? 'var(--bg-active)' : 'transparent')};
  color: var(--text-primary);
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 9px;
  transition: background var(--transition);
  position: relative;
  overflow: hidden;

  &:hover {
    background: ${(p) => (p.$active ? 'var(--bg-active)' : 'var(--bg-hover)')};
  }
`

const ConvIcon = styled.div<{ $active: boolean }>`
  width: 30px;
  height: 30px;
  border-radius: 8px;
  background: ${(p) => (p.$active ? 'var(--grad-primary)' : 'var(--bg-hover)')};
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: ${(p) => (p.$active ? '#fff' : 'var(--text-muted)')};
  flex-shrink: 0;
  transition: all var(--transition);
`

const ConvInfo = styled.div`
  overflow: hidden;
  flex: 1;
`

const ConvTitle = styled.div`
  font-size: 12.5px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--text-primary);
`

const ConvMeta = styled.div`
  font-size: 10.5px;
  color: var(--text-muted);
  margin-top: 1px;
`

const DeleteBtn = styled.button`
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  width: 22px;
  height: 22px;
  border-radius: 6px;
  border: none;
  background: rgba(239, 68, 68, 0.1);
  color: #ef4444;
  font-size: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transition: opacity 0.15s;
  cursor: pointer;

  ${ConvItem}:hover & { opacity: 1; }
`

const SidebarFooter = styled.div`
  border-top: 1px solid var(--border);
  padding: 10px 8px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex-shrink: 0;
`

const FooterLink = styled(Link)`
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border-radius: var(--radius-sm);
  color: var(--text-secondary);
  font-size: 12.5px;
  font-weight: 500;
  text-decoration: none;
  transition: all var(--transition);
  white-space: nowrap;
  overflow: hidden;

  i { width: 16px; text-align: center; flex-shrink: 0; font-size: 13px; }

  &:hover { background: var(--bg-hover); color: var(--primary); }
  &.active { background: var(--bg-active); color: var(--primary); }
`

const CollapseBtn = styled.button`
  width: 30px;
  height: 30px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--bg-hover);
  color: var(--text-secondary);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 11px;
  transition: all var(--transition);
  flex-shrink: 0;
  margin-left: auto;

  &:hover { background: var(--bg-active); color: var(--primary); }
`

const EmptyState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 32px 16px;
  gap: 10px;
  color: var(--text-muted);
  text-align: center;
  font-size: 12px;
  i { font-size: 28px; opacity: 0.35; }
`

// ── Component ─────────────────────────────────────────────
interface SidebarProps {
  settings: AppSettings
  onToggleCollapse: () => void
  onNavigate?: () => void
}

export default function Sidebar({ settings, onToggleCollapse, onNavigate }: SidebarProps) {
  const collapsed = settings.sidebarCollapsed
  const { conversations, activeConversationId, setActiveConversation, deleteConversation, newConversation } = useChatStore()
  const navigate = useNavigate()

  const handleNewChat = () => {
    newConversation()
    navigate('/')
    onNavigate?.()
  }

  const handleConvClick = (id: string) => {
    setActiveConversation(id)
    navigate('/')
    onNavigate?.()
  }

  const handleDelete = (e: React.MouseEvent, id: string) => {
    e.stopPropagation()
    deleteConversation(id)
  }

  return (
    <SidebarRoot $collapsed={collapsed}>
      {/* Header */}
      <SidebarHeader>
        <Logo><i className="fas fa-graduation-cap" /></Logo>
        {!collapsed && (
          <LogoText>
            <h2>MMU Chat</h2>
            <p>Campus Assistant</p>
          </LogoText>
        )}
        <CollapseBtn onClick={onToggleCollapse} title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}>
          <i className={`fas fa-chevron-${collapsed ? 'right' : 'left'}`} />
        </CollapseBtn>
      </SidebarHeader>

      {/* New Chat */}
      <NewChatBtn onClick={handleNewChat} title="New conversation">
        <i className="fas fa-plus" />
        {!collapsed && <span>New Chat</span>}
      </NewChatBtn>

      {/* Conversation list */}
      {!collapsed && (
        <>
          {conversations.length > 0 && <SectionLabel>Recent</SectionLabel>}
          <ConvList>
            {conversations.length === 0 ? (
              <EmptyState>
                <i className="fas fa-comment-dots" />
                <span>No conversations yet.<br />Start a new chat above!</span>
              </EmptyState>
            ) : (
              conversations.map((conv) => (
                <ConvItem
                  key={conv.id}
                  $active={conv.id === activeConversationId}
                  onClick={() => handleConvClick(conv.id)}
                >
                  <ConvIcon $active={conv.id === activeConversationId}>
                    <i className="fas fa-message" />
                  </ConvIcon>
                  <ConvInfo>
                    <ConvTitle>{conv.title}</ConvTitle>
                    <ConvMeta>
                      {formatDistanceToNow(new Date(conv.updatedAt), { addSuffix: true })}
                    </ConvMeta>
                  </ConvInfo>
                  <DeleteBtn onClick={(e) => handleDelete(e, conv.id)}>
                    <i className="fas fa-trash" />
                  </DeleteBtn>
                </ConvItem>
              ))
            )}
          </ConvList>
        </>
      )}

      {/* Collapsed: just icons */}
      {collapsed && (
        <ConvList>
          {conversations.slice(0, 8).map((conv) => (
            <ConvItem
              key={conv.id}
              $active={conv.id === activeConversationId}
              onClick={() => handleConvClick(conv.id)}
              title={conv.title}
            >
              <ConvIcon $active={conv.id === activeConversationId}>
                <i className="fas fa-message" />
              </ConvIcon>
            </ConvItem>
          ))}
        </ConvList>
      )}

      {/* Footer nav */}
      <SidebarFooter>
        <FooterLink to="/settings" title="Settings" onClick={onNavigate}>
          <i className="fas fa-cog" />
          {!collapsed && <span>Settings</span>}
        </FooterLink>
        <FooterLink to="https://mmu.ac.ug" target="_blank" rel="noopener" title="MMU Website">
          <i className="fas fa-external-link-alt" />
          {!collapsed && <span>MMU Website</span>}
        </FooterLink>
      </SidebarFooter>
    </SidebarRoot>
  )
}
