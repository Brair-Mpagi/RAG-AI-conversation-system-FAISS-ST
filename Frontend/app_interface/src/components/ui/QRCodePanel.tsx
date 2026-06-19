import { useState, useEffect } from 'react'
import styled from 'styled-components'

// ── Styled ────────────────────────────────────────────────
const Overlay = styled.div<{ $v: boolean }>`
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.6); backdrop-filter: blur(6px);
  z-index: 9500; display: flex; align-items: center; justify-content: center; padding: 20px;
  opacity: ${(p) => (p.$v ? 1 : 0)}; pointer-events: ${(p) => (p.$v ? 'auto' : 'none')};
  transition: opacity 0.25s;
`
const Panel = styled.div<{ $v: boolean }>`
  background: var(--bg-card); border-radius: var(--radius-xl);
  border: 1px solid var(--border); box-shadow: var(--shadow-xl);
  width: 100%; max-width: 400px; max-height: 90vh; overflow-y: auto;
  transform: ${(p) => (p.$v ? 'translateY(0) scale(1)' : 'translateY(28px) scale(0.95)')};
  transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  &::-webkit-scrollbar { width: 4px; }
  &::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
`
const Head = styled.div`
  background: var(--grad-primary); padding: 18px 20px 14px;
  display: flex; align-items: flex-start; justify-content: space-between;
  h2 { font-size: 17px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px; }
  p  { font-size: 12px; color: rgba(255,255,255,0.82); margin-top: 3px; line-height: 1.5; }
`
const CloseBtn = styled.button`
  width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
  border: 1px solid rgba(255,255,255,0.25); background: rgba(255,255,255,0.15);
  color: #fff; display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 12px; transition: background 0.15s;
  &:hover { background: rgba(255,255,255,0.3); }
`
const Body = styled.div` padding: 18px 20px; display: flex; flex-direction: column; gap: 14px; `

const ModeRow = styled.div`
  display: flex; border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden;
`
const ModeBtn = styled.button<{ $a: boolean }>`
  flex: 1; padding: 8px; border: none;
  background: ${(p) => p.$a ? 'var(--grad-primary)' : 'transparent'};
  color: ${(p) => p.$a ? '#fff' : 'var(--text-secondary)'};
  font-size: 12px; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  transition: all 0.15s;
  &:hover { background: ${(p) => p.$a ? '' : 'var(--bg-hover)'}; }
  &:disabled { opacity: 0.45; cursor: not-allowed; }
`
const QRBox = styled.div` display: flex; flex-direction: column; align-items: center; gap: 10px; `
const QRFrame = styled.div`
  width: 196px; height: 196px; border-radius: var(--radius-md);
  border: 3px solid var(--border); background: #fff;
  display: flex; align-items: center; justify-content: center;
  box-shadow: var(--shadow-md); overflow: hidden;
  img { width: 100%; height: 100%; display: block; }
`
const QRPlaceholder = styled.div`
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  color: var(--text-muted); font-size: 12px; padding: 16px; text-align: center;
  i { font-size: 28px; }
`
const URLBar = styled.div`
  width: 100%; display: flex; align-items: center; gap: 6px;
  background: var(--bg-hover); border: 1px solid var(--border);
  border-radius: var(--radius-md); padding: 8px 12px;
  font-family: monospace; font-size: 12px; color: var(--text-secondary);
  i { color: var(--primary); flex-shrink: 0; }
  span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
`
const CopyBtn = styled.button<{ $ok: boolean }>`
  flex-shrink: 0; padding: 3px 10px; border-radius: 6px; font-size: 11px; cursor: pointer;
  border: 1px solid ${(p) => p.$ok ? 'var(--online)' : 'var(--border)'};
  background: ${(p) => p.$ok ? 'rgba(16,185,129,0.1)' : 'var(--bg-card)'};
  color: ${(p) => p.$ok ? 'var(--online)' : 'var(--text-secondary)'};
  transition: all 0.15s; &:hover { color: var(--primary); border-color: var(--primary); }
`
const InfoBox = styled.div<{ $c: 'blue'|'amber'|'green' }>`
  border-radius: var(--radius-md); padding: 10px 12px;
  font-size: 12.5px; line-height: 1.55; color: var(--text-secondary);
  background: ${(p) => p.$c==='blue'?'rgba(102,126,234,0.08)':p.$c==='amber'?'rgba(245,158,11,0.08)':'rgba(16,185,129,0.08)'};
  border-left: 3px solid ${(p) => p.$c==='blue'?'var(--primary)':p.$c==='amber'?'#f59e0b':'#10b981'};
  code { background: rgba(0,0,0,0.06); border-radius: 3px; padding: 1px 5px; font-family: monospace; font-size: 11px; }
  strong { color: var(--text-primary); }
`
const IPRow = styled.div` display: flex; gap: 8px; `
const IPInput = styled.input`
  flex: 1; padding: 9px 12px; border-radius: var(--radius-md);
  border: 1.5px solid var(--border); background: var(--bg-input);
  color: var(--text-primary); font-size: 13px; font-family: monospace;
  outline: none; box-sizing: border-box; transition: border-color 0.15s;
  &:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
  &::placeholder { color: var(--text-muted); font-family: var(--font); }
`
const UseBtn = styled.button`
  padding: 9px 14px; border-radius: var(--radius-md); border: none;
  background: var(--grad-primary); color: #fff; font-size: 12px; font-weight: 700;
  cursor: pointer; white-space: nowrap; transition: all 0.15s;
  &:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); }
`
const Divider = styled.div` height: 1px; background: var(--border); `
const PlatformTabs = styled.div`
  display: flex; border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden; margin-bottom: 10px;
`
const PTab = styled.button<{ $a: boolean }>`
  flex: 1; padding: 7px; border: none;
  background: ${(p) => p.$a ? 'var(--grad-primary)' : 'transparent'};
  color: ${(p) => p.$a ? '#fff' : 'var(--text-secondary)'};
  font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.15s;
  display: flex; align-items: center; justify-content: center; gap: 5px;
  &:hover { background: ${(p) => p.$a ? '' : 'var(--bg-hover)'}; }
`
const Steps = styled.ol`
  padding-left: 18px; display: flex; flex-direction: column; gap: 5px;
  li { font-size: 12.5px; color: var(--text-secondary); line-height: 1.55; }
  strong { color: var(--text-primary); }
`

// ── QR image sub-component ────────────────────────────────
function QRImage({ url }: { url: string }) {
  const [st, setSt] = useState<'loading'|'ok'|'err'>('loading')
  const src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=6&data=${encodeURIComponent(url)}`

  useEffect(() => { setSt('loading') }, [url])

  return (
    <QRFrame>
      {st !== 'ok' && (
        <QRPlaceholder>
          {st === 'loading'
            ? <><i className="fas fa-spinner" style={{ animation: 'spin 1s linear infinite' }} /><span>Generating QR…</span></>
            : <><i className="fas fa-wifi-slash" /><span style={{ textAlign:'center', padding:'0 8px' }}>QR unavailable.<br/>Copy the URL below.</span></>
          }
        </QRPlaceholder>
      )}
      <img src={src} alt="QR" style={{ display: st === 'ok' ? 'block' : 'none' }}
        onLoad={() => setSt('ok')} onError={() => setSt('err')} />
    </QRFrame>
  )
}

// ── Main component ────────────────────────────────────────
export default function QRCodePanel({ isVisible, onClose }: { isVisible: boolean; onClose: () => void }) {
  const [mode, setMode]         = useState<'local'|'network'>('local')
  const [platform, setPlatform] = useState<'android'|'ios'>('android')
  const [netURL, setNetURL]     = useState('')       // from Vite plugin endpoint or WebRTC
  const [inputIP, setInputIP]   = useState('')
  const [copied, setCopied]     = useState(false)

  const port     = window.location.port || '5174'
  const hostname = window.location.hostname
  const isLocal  = hostname === 'localhost' || hostname === '127.0.0.1'
  const localURL = `http://${hostname}:${port}`

  // 1. If accessed via LAN IP already → use it
  useEffect(() => {
    if (!isLocal) { setNetURL(localURL); setMode('network') }
  }, [isLocal, localURL])

  // 2. Fetch network URL from Vite dev server middleware (most reliable)
  useEffect(() => {
    if (!isLocal) return
    fetch('/__network_url')
      .then((r) => r.json())
      .then((d: { url: string }) => {
        if (d.url && d.url !== localURL) { setNetURL(d.url); setMode('network') }
      })
      .catch(() => {/* fallback to WebRTC below */})
  }, [isLocal, localURL])

  // 3. WebRTC fallback
  useEffect(() => {
    if (!isLocal || netURL) return
    let done = false
    try {
      const pc = new RTCPeerConnection({ iceServers: [] })
      pc.createDataChannel('')
      pc.createOffer().then((o) => pc.setLocalDescription(o))
      pc.onicecandidate = (e) => {
        if (done || !e.candidate) return
        const m = e.candidate.candidate.match(/(\d+\.\d+\.\d+\.\d+)/)
        if (m && !m[1].startsWith('127.') && !m[1].startsWith('169.254.')) {
          done = true; setNetURL(`http://${m[1]}:${port}`); setMode('network'); pc.close()
        }
      }
      setTimeout(() => { done = true; try { pc.close() } catch {/**/} }, 3000)
    } catch {/**/}
  }, [isLocal, netURL, port])

  const manualNetURL = inputIP.trim() ? `http://${inputIP.trim()}:${port}` : ''
  const effectiveNetURL = netURL || manualNetURL
  const activeURL = mode === 'network' && effectiveNetURL ? effectiveNetURL : localURL

  const copy = async () => {
    try { await navigator.clipboard.writeText(activeURL); setCopied(true); setTimeout(() => setCopied(false), 2000) } catch {/**/}
  }

  return (
    <Overlay $v={isVisible} onClick={(e) => e.target === e.currentTarget && onClose()}>
      <Panel $v={isVisible}>
        <Head>
          <div>
            <h2><i className="fas fa-qrcode" /> Scan to Install</h2>
            <p>Open on your phone → Add to Home Screen → standalone app.</p>
          </div>
          <CloseBtn onClick={onClose}><i className="fas fa-times" /></CloseBtn>
        </Head>

        <Body>
          {/* Mode tabs */}
          <ModeRow>
            <ModeBtn $a={mode === 'local'} onClick={() => setMode('local')}>
              <i className="fas fa-laptop" /> This device
            </ModeBtn>
            <ModeBtn $a={mode === 'network'} onClick={() => effectiveNetURL && setMode('network')} disabled={!effectiveNetURL}>
              <i className="fas fa-mobile-alt" /> Phone / LAN
            </ModeBtn>
          </ModeRow>

          {/* QR + URL */}
          <QRBox>
            <QRImage url={activeURL} key={activeURL} />
            <URLBar>
              <i className="fas fa-link" />
              <span title={activeURL}>{activeURL}</span>
              <CopyBtn $ok={copied} onClick={copy}>
                {copied ? <><i className="fas fa-check" /> Copied</> : <><i className="fas fa-copy" /> Copy</>}
              </CopyBtn>
            </URLBar>
          </QRBox>

          {/* Context messages */}
          {mode === 'network' && effectiveNetURL && (
            <InfoBox $c="green">
              <strong><i className="fas fa-check-circle" style={{ marginRight:5 }} />Network URL ready</strong>
              Make sure your phone is on the <strong>same Wi-Fi</strong> as this computer.
            </InfoBox>
          )}
          {mode === 'local' && isLocal && (
            <InfoBox $c="amber">
              <strong>Switch to Phone / LAN for mobile scanning</strong>
              <code>localhost</code> only works on this computer. Use the network tab for your phone.
            </InfoBox>
          )}

          {/* Manual IP entry (shown if network not resolved) */}
          {!effectiveNetURL && isLocal && (
            <>
              <InfoBox $c="blue">
                <strong>Enter your LAN IP manually</strong>
                Check your terminal — it shows <code>Network: http://x.x.x.x:{port}/</code> when you run <code>npm run dev</code>.
              </InfoBox>
              <IPRow>
                <IPInput value={inputIP} onChange={(e) => setInputIP(e.target.value)}
                  placeholder={`e.g. 172.20.10.4`}
                  onKeyDown={(e) => e.key === 'Enter' && manualNetURL && (setNetURL(manualNetURL), setMode('network'))}
                />
                <UseBtn onClick={() => manualNetURL && (setNetURL(manualNetURL), setMode('network'))}>
                  Generate QR
                </UseBtn>
              </IPRow>
            </>
          )}

          <Divider />

          {/* Platform install steps */}
          <div>
            <PlatformTabs>
              <PTab $a={platform==='android'} onClick={() => setPlatform('android')}><i className="fab fa-android" /> Android</PTab>
              <PTab $a={platform==='ios'}     onClick={() => setPlatform('ios')}><i className="fab fa-apple" /> iPhone/iPad</PTab>
            </PlatformTabs>
            {platform === 'android' ? (
              <Steps>
                <li>Scan the QR with your camera or Chrome</li>
                <li>Opens in Chrome on your phone</li>
                <li>Tap <strong>⋮ menu</strong> → <strong>"Add to Home screen"</strong></li>
                <li>Tap <strong>Install</strong> → icon appears on home screen</li>
                <li>Open the icon → <strong>full-screen, no address bar ✓</strong></li>
              </Steps>
            ) : (
              <Steps>
                <li>Scan QR and open in <strong>Safari</strong> (not Chrome)</li>
                <li>Tap the <strong>Share</strong> button <i className="fas fa-arrow-up-from-bracket" style={{ fontSize:10 }} /></li>
                <li>Tap <strong>"Add to Home Screen"</strong></li>
                <li>Tap <strong>Add</strong> in the top-right</li>
                <li>Open from home screen → <strong>standalone, no Safari bar ✓</strong></li>
              </Steps>
            )}
          </div>
        </Body>
      </Panel>
    </Overlay>
  )
}
