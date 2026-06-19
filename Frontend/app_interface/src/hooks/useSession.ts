import { useState, useEffect, useRef, useCallback } from 'react'
import { chatAPI, type SessionStartRequest, type SessionStartResponse } from '../api/client'

const SESSION_ID_KEY    = 'mmu_app_session_id'
const SESSION_TOKEN_KEY = 'mmu_app_session_token'

export interface SessionInfo {
  sessionId: number | null
  sessionToken: string | null
  ready: boolean
}

export function useSession(): SessionInfo {
  const [sessionId, setSessionId]       = useState<number | null>(null)
  const [sessionToken, setSessionToken] = useState<string | null>(null)
  const [ready, setReady]               = useState(false)
  const heartbeatRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const startHeartbeat = useCallback((id: number, token: string) => {
    if (heartbeatRef.current) clearInterval(heartbeatRef.current)
    heartbeatRef.current = setInterval(async () => {
      try {
        await chatAPI.heartbeat({ session_id: id, session_token: token })
      } catch (e) {
        console.warn('Heartbeat failed', e)
      }
    }, 60_000)
  }, [])

  useEffect(() => {
    let cancelled = false

    ;(async () => {
      try {
        // 1. Restore existing session
        const storedId    = localStorage.getItem(SESSION_ID_KEY)
        const storedToken = localStorage.getItem(SESSION_TOKEN_KEY)
        if (storedId && storedToken) {
          const id = parseInt(storedId, 10)
          if (!cancelled) {
            setSessionId(id)
            setSessionToken(storedToken)
            setReady(true)
            startHeartbeat(id, storedToken)
          }
          return
        }

        // 2. Create a new session
        const ua        = navigator.userAgent || ''
        const platform  = (navigator as unknown as { platform?: string }).platform || ''
        const vendor    = (navigator as unknown as { vendor?: string }).vendor   || ''
        const screenRes = window.screen ? `${window.screen.width}x${window.screen.height}` : ''

        const payload: SessionStartRequest = {
          interface_type: 'web',
          access_mode: 'guest',
          browser_name: ua,
          browser_version: '',
          os_name: platform,
          device_brand: vendor,
          screen_resolution: screenRes,
        }

        // Attempt geolocation
        try {
          await new Promise<void>((resolve) => {
            if (!('geolocation' in navigator)) { resolve(); return }
            navigator.geolocation.getCurrentPosition(
              (pos) => { payload.location = `${pos.coords.latitude},${pos.coords.longitude}`; resolve() },
              () => resolve(),
              { enableHighAccuracy: false, timeout: 5000, maximumAge: 60000 }
            )
          })
        } catch { /* geo unavailable */ }

        if (cancelled) return

        const res: SessionStartResponse = await chatAPI.startSession(payload)
        localStorage.setItem(SESSION_ID_KEY,    String(res.session_id))
        localStorage.setItem(SESSION_TOKEN_KEY, res.session_token)

        if (!cancelled) {
          setSessionId(res.session_id)
          setSessionToken(res.session_token)
          setReady(true)
          startHeartbeat(res.session_id, res.session_token)
        }
      } catch (e) {
        console.warn('Session start failed — ephemeral mode', e)
        if (!cancelled) setReady(true)
      }
    })()

    return () => {
      cancelled = true
      if (heartbeatRef.current) clearInterval(heartbeatRef.current)
    }
  }, [startHeartbeat])

  return { sessionId, sessionToken, ready }
}
