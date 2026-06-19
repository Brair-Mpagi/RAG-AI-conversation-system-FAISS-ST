import { useState, useEffect, useCallback } from 'react'

// ── Types ─────────────────────────────────────────────────
export type Theme = 'light' | 'dark' | 'system'
export type FontSize = 'small' | 'medium' | 'large'
export type Density = 'compact' | 'comfortable' | 'spacious'
export type BubbleStyle = 'rounded' | 'flat'

export interface AppSettings {
  theme: Theme
  fontSize: FontSize
  density: Density
  animations: boolean
  bubbleStyle: BubbleStyle
  sidebarCollapsed: boolean
}

const SETTINGS_KEY = 'mmu_app_settings'

const DEFAULTS: AppSettings = {
  theme: 'system',
  fontSize: 'medium',
  density: 'comfortable',
  animations: true,
  bubbleStyle: 'rounded',
  sidebarCollapsed: false,
}

function load(): AppSettings {
  try {
    const raw = localStorage.getItem(SETTINGS_KEY)
    if (raw) return { ...DEFAULTS, ...JSON.parse(raw) }
  } catch { /* ignore */ }
  return DEFAULTS
}

function save(s: AppSettings) {
  try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(s)) } catch { /* ignore */ }
}

// Resolve actual theme from system preference
function resolveTheme(theme: Theme): 'light' | 'dark' {
  if (theme === 'system') {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  }
  return theme
}

// Apply tokens to <html> element
function applyTokens(settings: AppSettings) {
  const html = document.documentElement
  const resolved = resolveTheme(settings.theme)
  html.setAttribute('data-theme', resolved)
  html.setAttribute('data-fontsize', settings.fontSize)
  html.setAttribute('data-density', settings.density)
  html.setAttribute('data-animations', String(settings.animations))
}

// ── Hook ──────────────────────────────────────────────────
export function useSettings() {
  const [settings, setSettingsState] = useState<AppSettings>(load)

  // Apply on mount + whenever settings change
  useEffect(() => {
    applyTokens(settings)
  }, [settings])

  // Listen for system theme changes
  useEffect(() => {
    if (settings.theme !== 'system') return
    const mq = window.matchMedia('(prefers-color-scheme: dark)')
    const handler = () => applyTokens(settings)
    mq.addEventListener('change', handler)
    return () => mq.removeEventListener('change', handler)
  }, [settings])

  const updateSettings = useCallback((patch: Partial<AppSettings>) => {
    setSettingsState((prev) => {
      const next = { ...prev, ...patch }
      save(next)
      applyTokens(next)
      return next
    })
  }, [])

  const resetSettings = useCallback(() => {
    save(DEFAULTS)
    applyTokens(DEFAULTS)
    setSettingsState(DEFAULTS)
  }, [])

  return { settings, updateSettings, resetSettings }
}
