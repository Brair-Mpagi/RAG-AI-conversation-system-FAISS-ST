import styled from 'styled-components'
import React from 'react'
import { useChatStore } from '../store/chatStore'
import type { AppSettings, Theme, FontSize, Density, BubbleStyle } from '../hooks/useSettings'
import { useToast } from '../components/ui/Toast'

// ── Styled ────────────────────────────────────────────────
const PageRoot = styled.div`
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow-y: auto;
  background: var(--bg-main);
`

const PageHeader = styled.div`
  padding: 24px 28px 0;
  border-bottom: 1px solid var(--border);
  background: var(--bg-sidebar);
  flex-shrink: 0;

  h1 {
    font-size: 22px;
    font-weight: 800;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 10px;
    i { color: var(--primary); }
  }

  p {
    font-size: 13px;
    color: var(--text-secondary);
    margin-top: 4px;
    padding-bottom: 20px;
  }

  @media (max-width: 768px) { padding: 16px 16px 0; }
`

const Content = styled.div`
  padding: 24px 28px;
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 680px;

  @media (max-width: 768px) { padding: 16px; }
`

const Card = styled.div`
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
`

const CardHeader = styled.div`
  padding: 16px 20px 14px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 10px;

  .icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: var(--bg-active);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
  }

  h2 {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
  }
  p {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 2px;
  }
`

const CardBody = styled.div`
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
`

const SettingRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  min-height: 38px;

  .label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
  }
  .sub {
    font-size: 11.5px;
    color: var(--text-muted);
    margin-top: 2px;
  }
`

const ChipGroup = styled.div`
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
`

const Chip = styled.button<{ $active: boolean }>`
  padding: 6px 14px;
  border-radius: var(--radius-full);
  border: 1.5px solid ${(p) => (p.$active ? 'var(--primary)' : 'var(--border)')};
  background: ${(p) => (p.$active ? 'var(--bg-active)' : 'transparent')};
  color: ${(p) => (p.$active ? 'var(--primary)' : 'var(--text-secondary)')};
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all var(--transition);
  white-space: nowrap;
  &:hover { border-color: var(--primary); color: var(--primary); }
`

const Toggle = styled.button<{ $on: boolean }>`
  width: 48px;
  height: 26px;
  border-radius: 13px;
  border: none;
  background: ${(p) => (p.$on ? 'var(--primary)' : 'var(--border)')};
  position: relative;
  cursor: pointer;
  flex-shrink: 0;
  transition: background var(--transition);
  box-shadow: inset 0 1px 3px rgba(0,0,0,0.15);

  &::after {
    content: '';
    position: absolute;
    top: 3px;
    left: ${(p) => (p.$on ? '25px' : '3px')};
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,0.2);
    transition: left var(--transition);
  }
`

const DangerBtn = styled.button`
  padding: 9px 18px;
  border-radius: var(--radius-md);
  border: 1.5px solid rgba(239,68,68,0.4);
  background: rgba(239,68,68,0.06);
  color: #ef4444;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: all var(--transition);
  &:hover { background: rgba(239,68,68,0.12); border-color: #ef4444; }
`

const ResetBtn = styled.button`
  padding: 9px 18px;
  border-radius: var(--radius-md);
  border: 1px solid var(--border);
  background: var(--bg-hover);
  color: var(--text-secondary);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: all var(--transition);
  &:hover { background: var(--bg-active); color: var(--text-primary); }
`

const VersionNote = styled.p`
  font-size: 12px;
  color: var(--text-muted);
  text-align: center;
  padding: 8px 0 24px;
`

// ── Component ─────────────────────────────────────────────
interface SettingsPageProps {
  settings: AppSettings
  onUpdate: (patch: Partial<AppSettings>) => void
  onReset: () => void
}

export default function SettingsPage({ settings, onUpdate, onReset }: SettingsPageProps) {
  const { clearAllHistory } = useChatStore()
  const { showToast } = useToast()
  const [deferredPrompt, setDeferredPrompt] = React.useState<any>(null)
  const [isInstalled, setIsInstalled] = React.useState(false)

  // Listen for PWA install prompt
  React.useEffect(() => {
    const handleBeforeInstall = (e: Event) => {
      e.preventDefault()
      setDeferredPrompt(e)
    }

    const checkIfInstalled = () => {
      // Check if app is already installed
      if (window.matchMedia('(display-mode: standalone)').matches) {
        setIsInstalled(true)
      }
    }

    window.addEventListener('beforeinstallprompt', handleBeforeInstall)
    window.addEventListener('appinstalled', () => {
      setIsInstalled(true)
      setDeferredPrompt(null)
      showToast('App installed successfully! Find it in your apps.', 'success')
    })
    
    checkIfInstalled()

    return () => {
      window.removeEventListener('beforeinstallprompt', handleBeforeInstall)
    }
  }, [showToast])

  const handleClearHistory = () => {
    if (window.confirm('Delete all local chat history? This cannot be undone.')) {
      clearAllHistory()
      showToast('Chat history cleared', 'success')
    }
  }

  const handleReset = () => {
    if (window.confirm('Reset all settings to defaults?')) {
      onReset()
      showToast('Settings reset to defaults', 'info')
    }
  }

  const installPWA = async () => {
    if (!deferredPrompt) {
      if (isInstalled) {
        showToast('App is already installed!', 'info')
      } else {
        showToast('Install option not available. Try using Chrome/Edge menu > Install App', 'info')
      }
      return
    }

    // Show the install prompt
    deferredPrompt.prompt()
    
    // Wait for the user's response
    const { outcome } = await deferredPrompt.userChoice
    
    if (outcome === 'accepted') {
      showToast('Installing app...', 'success')
    } else {
      showToast('Installation cancelled', 'info')
    }
    
    setDeferredPrompt(null)
  }

  const downloadDesktopShortcut = (platform: 'windows' | 'linux') => {
    const appUrl = window.location.origin
    const appName = 'MMU Campus Assistant'
    
    let fileContent: string
    let fileName: string
    let mimeType: string

    if (platform === 'windows') {
      // Windows: Create a batch script that launches in Chrome app mode
      fileContent = `@echo off
REM MMU Campus Assistant Launcher
REM Opens the app in standalone mode (no browser UI)

REM Try Chrome first, then Edge, then default browser
if exist "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe" (
    start "" "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe" --app=${appUrl}
) else if exist "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe" (
    start "" "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe" --app=${appUrl}
) else if exist "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe" (
    start "" "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe" --app=${appUrl}
) else (
    start "" "${appUrl}"
)
`
      fileName = 'MMU-Chat.bat'
      mimeType = 'application/bat'
    } else {
      // Linux: Download a shell installer that creates and runs the desktop file
      // Uses Chrome/Chromium app mode for standalone window (no browser UI)
      fileContent = `#!/bin/bash
# MMU Campus Assistant - Desktop Launcher Installer
# This script creates and installs the desktop launcher as a standalone app

echo "🎓 MMU Campus Assistant - Installing Desktop Launcher..."

# Get the real user (even if run with sudo)
if [ -n "\$SUDO_USER" ]; then
    REAL_USER="\$SUDO_USER"
    REAL_HOME=$(getent passwd "\$SUDO_USER" | cut -d: -f6)
else
    REAL_USER="\$USER"
    REAL_HOME="\$HOME"
fi

echo "📁 Installing for user: \$REAL_USER"

# Detect available browser with app mode support
BROWSER=""
if command -v google-chrome &> /dev/null; then
    BROWSER="google-chrome"
elif command -v chromium &> /dev/null; then
    BROWSER="chromium"
elif command -v chromium-browser &> /dev/null; then
    BROWSER="chromium-browser"
elif command -v google-chrome-stable &> /dev/null; then
    BROWSER="google-chrome-stable"
elif command -v brave-browser &> /dev/null; then
    BROWSER="brave-browser"
elif command -v microsoft-edge &> /dev/null; then
    BROWSER="microsoft-edge"
else
    echo "⚠️  No Chromium-based browser found. Will use default browser (may open in tab)."
    BROWSER="xdg-open"
fi

echo "✅ Using browser: \$BROWSER"

# Create the desktop file with app mode
cat > MMU-Chat.desktop << EOF
[Desktop Entry]
Version=1.0
Type=Application
Name=${appName}
Comment=MMU AI Chatbot - Campus Information Assistant
Exec=\$BROWSER --app=${appUrl} --class=MMUChat
Icon=web-browser
Terminal=false
Categories=Network;Education;
StartupNotify=true
StartupWMClass=MMUChat
EOF

# Make it executable
chmod +x MMU-Chat.desktop

echo "✅ Desktop launcher created!"
echo ""
echo "Choose installation location:"
echo "1) Desktop (\$REAL_HOME/Desktop/) - Creates desktop icon"
echo "2) Applications Menu (\$REAL_HOME/.local/share/applications/) - Adds to app menu"
echo "3) Both"
echo "4) Just run once (don't install)"
echo ""
read -p "Enter choice (1-4): " choice

case "\$choice" in
    1)
        mkdir -p "\$REAL_HOME/Desktop"
        cp MMU-Chat.desktop "\$REAL_HOME/Desktop/"
        chmod +x "\$REAL_HOME/Desktop/MMU-Chat.desktop"
        chown "\$REAL_USER:\$REAL_USER" "\$REAL_HOME/Desktop/MMU-Chat.desktop" 2>/dev/null
        echo "✅ Installed to Desktop!"
        ;;
    2)
        mkdir -p "\$REAL_HOME/.local/share/applications"
        cp MMU-Chat.desktop "\$REAL_HOME/.local/share/applications/"
        chown "\$REAL_USER:\$REAL_USER" "\$REAL_HOME/.local/share/applications/MMU-Chat.desktop" 2>/dev/null
        echo "✅ Installed to Applications Menu!"
        ;;
    3)
        mkdir -p "\$REAL_HOME/Desktop"
        mkdir -p "\$REAL_HOME/.local/share/applications"
        cp MMU-Chat.desktop "\$REAL_HOME/Desktop/"
        chmod +x "\$REAL_HOME/Desktop/MMU-Chat.desktop"
        cp MMU-Chat.desktop "\$REAL_HOME/.local/share/applications/"
        chown "\$REAL_USER:\$REAL_USER" "\$REAL_HOME/Desktop/MMU-Chat.desktop" 2>/dev/null
        chown "\$REAL_USER:\$REAL_USER" "\$REAL_HOME/.local/share/applications/MMU-Chat.desktop" 2>/dev/null
        echo "✅ Installed to Desktop and Applications Menu!"
        ;;
    4)
        sudo -u "\$REAL_USER" \$BROWSER --app=${appUrl} &
        echo "✅ Launched! (Not installed)"
        ;;
    *)
        echo "Invalid choice. Installing to Desktop..."
        mkdir -p "\$REAL_HOME/Desktop"
        cp MMU-Chat.desktop "\$REAL_HOME/Desktop/"
        chmod +x "\$REAL_HOME/Desktop/MMU-Chat.desktop"
        chown "\$REAL_USER:\$REAL_USER" "\$REAL_HOME/Desktop/MMU-Chat.desktop" 2>/dev/null
        ;;
esac

echo ""
echo "🎉 Done! App will open in standalone mode (no browser tabs/UI)."
echo "💡 Tip: You don't need sudo to run this script next time!"
echo ""
`
      fileName = 'install-mmu-chat.sh'
      mimeType = 'application/x-sh'
    }

    // Create blob and download
    const blob = new Blob([fileContent], { type: mimeType })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = fileName
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)

    // Show instructions based on platform
    if (platform === 'linux') {
      showToast('Installer downloaded! Run: bash ~/Downloads/install-mmu-chat.sh', 'success')
    } else {
      showToast(`${fileName} downloaded! Double-click to launch the app in standalone mode.`, 'success')
    }
  }

  const themeOptions: { value: Theme; label: string; icon: string }[] = [
    { value: 'light',  label: 'Light',  icon: 'fa-sun' },
    { value: 'dark',   label: 'Dark',   icon: 'fa-moon' },
    { value: 'system', label: 'System', icon: 'fa-desktop' },
  ]

  const fontOptions: { value: FontSize; label: string }[] = [
    { value: 'small',  label: 'Small (13px)' },
    { value: 'medium', label: 'Medium (14px)' },
    { value: 'large',  label: 'Large (16px)' },
  ]

  const densityOptions: { value: Density; label: string }[] = [
    { value: 'compact',     label: 'Compact' },
    { value: 'comfortable', label: 'Comfortable' },
    { value: 'spacious',    label: 'Spacious' },
  ]

  const bubbleOptions: { value: BubbleStyle; label: string }[] = [
    { value: 'rounded', label: 'Rounded' },
    { value: 'flat',    label: 'Flat' },
  ]

  return (
    <PageRoot>
      <PageHeader>
        <h1><i className="fas fa-cog" /> Settings</h1>
        <p>Customise your MMU Chat experience. All settings are saved locally.</p>
      </PageHeader>

      <Content>
        {/* Appearance */}
        <Card>
          <CardHeader>
            <div className="icon"><i className="fas fa-palette" /></div>
            <div>
              <h2>Appearance</h2>
              <p>Theme, colour mode, and visual style</p>
            </div>
          </CardHeader>
          <CardBody>
            <SettingRow>
              <div>
                <div className="label">Theme</div>
                <div className="sub">Choose your preferred colour scheme</div>
              </div>
              <ChipGroup>
                {themeOptions.map((o) => (
                  <Chip key={o.value} $active={settings.theme === o.value} onClick={() => onUpdate({ theme: o.value })}>
                    <i className={`fas ${o.icon}`} style={{ marginRight: 5 }} />{o.label}
                  </Chip>
                ))}
              </ChipGroup>
            </SettingRow>

            <SettingRow>
              <div>
                <div className="label">Chat bubble style</div>
                <div className="sub">How message bubbles are shaped</div>
              </div>
              <ChipGroup>
                {bubbleOptions.map((o) => (
                  <Chip key={o.value} $active={settings.bubbleStyle === o.value} onClick={() => onUpdate({ bubbleStyle: o.value })}>
                    {o.label}
                  </Chip>
                ))}
              </ChipGroup>
            </SettingRow>
          </CardBody>
        </Card>

        {/* Typography & Layout */}
        <Card>
          <CardHeader>
            <div className="icon"><i className="fas fa-text-height" /></div>
            <div>
              <h2>Typography & Layout</h2>
              <p>Font size and message spacing</p>
            </div>
          </CardHeader>
          <CardBody>
            <SettingRow>
              <div>
                <div className="label">Font size</div>
                <div className="sub">Controls all text in the app</div>
              </div>
              <ChipGroup>
                {fontOptions.map((o) => (
                  <Chip key={o.value} $active={settings.fontSize === o.value} onClick={() => onUpdate({ fontSize: o.value })}>
                    {o.label}
                  </Chip>
                ))}
              </ChipGroup>
            </SettingRow>

            <SettingRow>
              <div>
                <div className="label">Layout density</div>
                <div className="sub">Spacing between messages</div>
              </div>
              <ChipGroup>
                {densityOptions.map((o) => (
                  <Chip key={o.value} $active={settings.density === o.value} onClick={() => onUpdate({ density: o.value })}>
                    {o.label}
                  </Chip>
                ))}
              </ChipGroup>
            </SettingRow>
          </CardBody>
        </Card>

        {/* Behaviour */}
        <Card>
          <CardHeader>
            <div className="icon"><i className="fas fa-sliders" /></div>
            <div>
              <h2>Behaviour</h2>
              <p>Animations and sidebar preferences</p>
            </div>
          </CardHeader>
          <CardBody>
            <SettingRow>
              <div>
                <div className="label">Animations</div>
                <div className="sub">Smooth transitions and message slide-ins</div>
              </div>
              <Toggle $on={settings.animations} onClick={() => onUpdate({ animations: !settings.animations })} />
            </SettingRow>
            <SettingRow>
              <div>
                <div className="label">Collapsed sidebar</div>
                <div className="sub">Show only icons in the sidebar</div>
              </div>
              <Toggle $on={settings.sidebarCollapsed} onClick={() => onUpdate({ sidebarCollapsed: !settings.sidebarCollapsed })} />
            </SettingRow>
          </CardBody>
        </Card>

        {/* Data & Privacy */}
        <Card>
          <CardHeader>
            <div className="icon"><i className="fas fa-shield-halved" /></div>
            <div>
              <h2>Data & Privacy</h2>
              <p>Manage locally stored data</p>
            </div>
          </CardHeader>
          <CardBody>
            <SettingRow>
              <div>
                <div className="label">Chat history</div>
                <div className="sub">All conversations are stored in your browser only</div>
              </div>
              <DangerBtn onClick={handleClearHistory}>
                <i className="fas fa-trash" /> Clear history
              </DangerBtn>
            </SettingRow>
            <SettingRow>
              <div>
                <div className="label">Settings</div>
                <div className="sub">Restore all preferences to their defaults</div>
              </div>
              <ResetBtn onClick={handleReset}>
                <i className="fas fa-rotate-left" /> Reset defaults
              </ResetBtn>
            </SettingRow>
          </CardBody>
        </Card>

        {/* About */}
        <Card>
          <CardHeader>
            <div className="icon"><i className="fas fa-info-circle" /></div>
            <div>
              <h2>About</h2>
              <p>MMU Campus Assistant App</p>
            </div>
          </CardHeader>
          <CardBody>
            <SettingRow>
              <div className="label">Connected to</div>
              <span style={{ fontSize: 12, color: 'var(--text-muted)', fontFamily: 'monospace' }}>
                {window.location.hostname}:8000
              </span>
            </SettingRow>
            <SettingRow>
              <div className="label">University</div>
              <a href="https://mmu.ac.ug" target="_blank" rel="noopener"
                style={{ fontSize: 12, color: 'var(--primary)', display: 'flex', alignItems: 'center', gap: 4 }}>
                mmu.ac.ug <i className="fas fa-external-link-alt" style={{ fontSize: 10 }} />
              </a>
            </SettingRow>
          </CardBody>
        </Card>

        {/* Desktop Shortcut */}
        <Card>
          <CardHeader>
            <div className="icon"><i className="fas fa-download" /></div>
            <div>
              <h2>Install Desktop App</h2>
              <p>Install as a native app on your device (recommended)</p>
            </div>
          </CardHeader>
          <CardBody>
            {isInstalled ? (
              <SettingRow>
                <div>
                  <div className="label">✅ App Installed</div>
                  <div className="sub">MMU Campus Assistant is installed on your device</div>
                </div>
                <div style={{ 
                  padding: '8px 16px', 
                  borderRadius: 'var(--radius-md)', 
                  background: 'var(--bg-active)',
                  color: 'var(--primary)',
                  fontSize: '12px',
                  fontWeight: 600
                }}>
                  <i className="fas fa-check-circle" /> Installed
                </div>
              </SettingRow>
            ) : (
              <>
                <SettingRow>
                  <div>
                    <div className="label">Quick Install (PWA)</div>
                    <div className="sub">Install as a native app - works offline, launches like a real app</div>
                  </div>
                  <ResetBtn 
                    onClick={installPWA}
                    style={{
                      background: 'linear-gradient(135deg, #002147 0%, #05356b 100%)',
                      color: 'white',
                      border: 'none'
                    }}
                  >
                    <i className="fas fa-download" /> Install App
                  </ResetBtn>
                </SettingRow>
                
                <div style={{ 
                  marginTop: '12px', 
                  padding: '12px', 
                  background: 'var(--bg-hover)', 
                  borderRadius: '8px',
                  fontSize: '11.5px',
                  color: 'var(--text-muted)',
                  lineHeight: '1.6'
                }}>
                  <strong style={{ color: 'var(--text-secondary)' }}>Why Install?</strong><br />
                  • Opens in its own window (no browser tabs)<br />
                  • Works offline after first install<br />
                  • Faster launch from desktop/app menu<br />
                  • Native app-like experience
                </div>

                <hr style={{ margin: '16px 0', border: 'none', borderTop: '1px solid var(--border)' }} />

                <div style={{ 
                  fontSize: '12px', 
                  color: 'var(--text-muted)',
                  marginBottom: '12px',
                  fontWeight: 600
                }}>
                  Alternative: Download Launcher
                </div>

                <SettingRow>
                  <div>
                    <div className="label">Windows Launcher</div>
                    <div className="sub">Download batch file to launch in app mode</div>
                  </div>
                  <ResetBtn onClick={() => downloadDesktopShortcut('windows')}>
                    <i className="fab fa-windows" /> Download
                  </ResetBtn>
                </SettingRow>
                <SettingRow>
                  <div>
                    <div className="label">Linux Launcher</div>
                    <div className="sub">Download installer script for desktop launcher</div>
                  </div>
                  <ResetBtn onClick={() => downloadDesktopShortcut('linux')}>
                    <i className="fab fa-linux" /> Download
                  </ResetBtn>
                </SettingRow>
              </>
            )}
          </CardBody>
        </Card>

        <VersionNote>MMU Campus Assistant v1.0 — All data stored locally in your browser</VersionNote>
      </Content>
    </PageRoot>
  )
}
