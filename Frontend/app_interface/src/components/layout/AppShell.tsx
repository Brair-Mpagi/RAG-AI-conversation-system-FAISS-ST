import { useState, useEffect } from 'react'
import styled from 'styled-components'
import { Outlet, useLocation } from 'react-router-dom'
import Sidebar from './Sidebar'
import MobileNav from './MobileNav'
import type { AppSettings } from '../../hooks/useSettings'
import QRCodePanel from '../ui/QRCodePanel'

const Shell = styled.div`
  display: flex;
  height: 100%;
  overflow: hidden;
  background: var(--bg-app);
`

const Main = styled.main`
  flex: 1;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  min-width: 0;
`

const MobileHeader = styled.header`
  display: none;
  padding: 12px 16px;
  padding-top: calc(12px + max(env(safe-area-inset-top), 24px));
  align-items: center;
  border-bottom: 1px solid var(--border);
  background: var(--bg-sidebar);
  gap: 12px;
  flex-shrink: 0;

  @media (max-width: 768px) { display: flex; }
`

const HamburgerBtn = styled.button`
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--bg-hover);
  color: var(--text-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  cursor: pointer;
  transition: all var(--transition);
  &:hover { background: var(--bg-active); color: var(--primary); }
`

const MobileTitle = styled.div`
  h1 {
    font-size: 16px;
    font-weight: 800;
    background: var(--grad-primary);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }
  p { font-size: 11px; color: var(--text-muted); margin-top: 1px; }
`

const MobileDrawerOverlay = styled.div<{ $open: boolean }>`
  display: none;
  @media (max-width: 768px) {
    display: block;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    z-index: 100;
    opacity: ${(p) => (p.$open ? 1 : 0)};
    pointer-events: ${(p) => (p.$open ? 'auto' : 'none')};
    transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }
`

const MobileDrawer = styled.div<{ $open: boolean }>`
  display: none;
  @media (max-width: 768px) {
    display: flex;
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 101;
    transform: ${(p) => (p.$open ? 'translateX(0)' : 'translateX(-100%)')};
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    /* Add safe area padding for mobile devices */
    padding-top: max(env(safe-area-inset-top), 32px);
  }
`

const DesktopSidebar = styled.div`
  @media (max-width: 768px) { display: none; }
`

const ContentArea = styled.div`
  flex: 1;
  overflow: hidden;
  display: flex;
  flex-direction: column;

  @media (max-width: 768px) {
    padding-bottom: 56px; /* room for MobileNav */
  }
`

interface AppShellProps {
  settings: AppSettings
  onToggleSidebar: () => void
}

export default function AppShell({ settings, onToggleSidebar }: AppShellProps) {
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [isMobile, setIsMobile]     = useState(window.innerWidth <= 768)
  const [showQR, setShowQR]         = useState(false)
  const location = useLocation()

  useEffect(() => {
    const handler = () => setIsMobile(window.innerWidth <= 768)
    window.addEventListener('resize', handler)
    return () => window.removeEventListener('resize', handler)
  }, [])
  useEffect(() => {
    if (!isMobile) setDrawerOpen(false)
  }, [isMobile])

  // Auto-close drawer when navigating
  useEffect(() => {
    setDrawerOpen(false)
  }, [location.pathname])

  return (
    <Shell>
      {/* Desktop sidebar */}
      <DesktopSidebar>
        <Sidebar settings={settings} onToggleCollapse={onToggleSidebar} />
      </DesktopSidebar>

      {/* Mobile drawer overlay */}
      <MobileDrawerOverlay $open={drawerOpen} onClick={() => setDrawerOpen(false)} />
      <MobileDrawer $open={drawerOpen}>
        <Sidebar
          settings={settings}
          onToggleCollapse={() => setDrawerOpen(false)}
          onNavigate={() => setDrawerOpen(false)}
        />
      </MobileDrawer>

      <Main>
        {/* Mobile top header */}
        <MobileHeader>
          <HamburgerBtn onClick={() => setDrawerOpen(true)}>
            <i className="fas fa-bars" />
          </HamburgerBtn>
          <MobileTitle>
            <h1>MMU Chat</h1>
            <p>Campus Assistant</p>
          </MobileTitle>
          {/* QR code install button — mobile header right side (hide in native) */}
          {/* @ts-ignore */}
          {!(typeof window !== 'undefined' && window.Capacitor?.isNativePlatform()) && (
            <HamburgerBtn
              onClick={() => setShowQR(true)}
              style={{ marginLeft: 'auto', color: 'var(--primary)', borderColor: 'var(--primary)' }}
              title="Install as app"
            >
              <i className="fas fa-qrcode" />
            </HamburgerBtn>
          )}
        </MobileHeader>

        <ContentArea>
          <Outlet />
        </ContentArea>
      </Main>

      {/* Mobile bottom nav */}
      {isMobile && <MobileNav />}

      {/* QR / Install panel */}
      <QRCodePanel isVisible={showQR} onClose={() => setShowQR(false)} />
    </Shell>
  )
}
