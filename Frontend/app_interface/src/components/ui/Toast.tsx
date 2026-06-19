import React, { createContext, useContext, useCallback, useRef, useState } from 'react'

// ── Types ─────────────────────────────────────────────────
type ToastType = 'success' | 'error' | 'warning' | 'info'

interface Toast {
  id: string
  message: string
  type: ToastType
}

interface ToastContextValue {
  showToast: (message: string, type?: ToastType) => void
}

const ToastContext = createContext<ToastContextValue>({ showToast: () => {} })

export const useToast = () => useContext(ToastContext)

// ── Provider ──────────────────────────────────────────────
export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])
  const counter = useRef(0)

  const showToast = useCallback((message: string, type: ToastType = 'info') => {
    const id = String(++counter.current)
    setToasts((prev) => [...prev, { id, message, type }])
    setTimeout(() => {
      setToasts((prev) => prev.filter((t) => t.id !== id))
    }, 4000)
  }, [])

  const icons: Record<ToastType, string> = {
    success: 'fa-check-circle',
    error:   'fa-exclamation-circle',
    warning: 'fa-exclamation-triangle',
    info:    'fa-info-circle',
  }

  const gradients: Record<ToastType, string> = {
    success: 'linear-gradient(135deg,#10b981,#059669)',
    error:   'linear-gradient(135deg,#ef4444,#dc2626)',
    warning: 'linear-gradient(135deg,#f59e0b,#d97706)',
    info:    'linear-gradient(135deg,#667eea,#764ba2)',
  }

  return (
    <ToastContext.Provider value={{ showToast }}>
      {children}
      <div style={{
        position: 'fixed',
        bottom: 24,
        left: '50%',
        transform: 'translateX(-50%)',
        zIndex: 99999,
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 8,
        pointerEvents: 'none',
      }}>
        {toasts.map((t) => (
          <div
            key={t.id}
            style={{
              background: gradients[t.type],
              color: '#fff',
              padding: '10px 20px',
              borderRadius: 10,
              fontSize: 13,
              fontWeight: 500,
              display: 'flex',
              alignItems: 'center',
              gap: 8,
              boxShadow: '0 4px 20px rgba(0,0,0,0.3)',
              animation: 'toastSlide 0.3s ease',
              whiteSpace: 'nowrap',
              maxWidth: '90vw',
            }}
          >
            <i className={`fas ${icons[t.type]}`} />
            {t.message}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}
