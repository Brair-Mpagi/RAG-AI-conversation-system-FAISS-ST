import { useEffect, useState } from 'react'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export function InstallPrompt() {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null)
  const [show, setShow] = useState(false)
  const [dismissed, setDismissed] = useState(
    () => localStorage.getItem('mmu_pwa_dismissed') === '1'
  )

  useEffect(() => {
    const handler = (e: Event) => {
      e.preventDefault()
      setDeferredPrompt(e as BeforeInstallPromptEvent)
      if (!dismissed) setShow(true)
    }
    window.addEventListener('beforeinstallprompt', handler)
    return () => window.removeEventListener('beforeinstallprompt', handler)
  }, [dismissed])

  const handleInstall = async () => {
    if (!deferredPrompt) return
    await deferredPrompt.prompt()
    const { outcome } = await deferredPrompt.userChoice
    if (outcome === 'accepted') setShow(false)
    setDeferredPrompt(null)
  }

  const handleDismiss = () => {
    setShow(false)
    setDismissed(true)
    localStorage.setItem('mmu_pwa_dismissed', '1')
  }

  if (!show) return null
  // @ts-ignore
  if (typeof window !== 'undefined' && window.Capacitor?.isNativePlatform()) return null

  return (
    <div style={{
      position: 'fixed',
      bottom: 24,
      right: 24,
      background: 'var(--bg-card)',
      border: '1px solid var(--border)',
      borderRadius: 16,
      padding: '16px 20px',
      boxShadow: 'var(--shadow-xl)',
      zIndex: 9000,
      width: 300,
      animation: 'slideUp 0.3s ease',
    }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
        <div style={{
          width: 42,
          height: 42,
          borderRadius: 10,
          background: 'var(--grad-primary)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          flexShrink: 0,
          fontSize: 18,
          color: '#fff',
        }}>
          <i className="fas fa-mobile-alt" />
        </div>
        <div style={{ flex: 1 }}>
          <p style={{ fontWeight: 700, fontSize: 13, color: 'var(--text-primary)', marginBottom: 3 }}>
            Install MMU Chat
          </p>
          <p style={{ fontSize: 12, color: 'var(--text-secondary)', lineHeight: 1.5 }}>
            Add to your home screen for quick, native-like access.
          </p>
        </div>
        <button
          onClick={handleDismiss}
          style={{ color: 'var(--text-muted)', fontSize: 14, flexShrink: 0, padding: 4 }}
        >
          <i className="fas fa-times" />
        </button>
      </div>
      <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
        <button
          onClick={handleDismiss}
          style={{
            flex: 1,
            padding: '8px 0',
            borderRadius: 8,
            border: '1px solid var(--border)',
            background: 'transparent',
            color: 'var(--text-secondary)',
            fontSize: 13,
            fontWeight: 500,
            cursor: 'pointer',
          }}
        >
          Not now
        </button>
        <button
          onClick={handleInstall}
          style={{
            flex: 1,
            padding: '8px 0',
            borderRadius: 8,
            border: 'none',
            background: 'var(--grad-primary)',
            color: '#fff',
            fontSize: 13,
            fontWeight: 600,
            cursor: 'pointer',
          }}
        >
          <i className="fas fa-download" style={{ marginRight: 6 }} />
          Install
        </button>
      </div>
    </div>
  )
}
