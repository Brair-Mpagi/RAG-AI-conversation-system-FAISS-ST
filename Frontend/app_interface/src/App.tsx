import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { useSettings } from './hooks/useSettings'
import { useSession } from './hooks/useSession'
import { ToastProvider } from './components/ui/Toast'
import { ErrorBoundary } from './components/ui/ErrorBoundary'
import { InstallPrompt } from './components/ui/InstallPrompt'
import AppShell from './components/layout/AppShell'
import ChatPage from './pages/ChatPage'
import SettingsPage from './pages/SettingsPage'

export default function App() {
  const { settings, updateSettings, resetSettings } = useSettings()
  const session = useSession()

  return (
    <ErrorBoundary>
      <ToastProvider>
        <BrowserRouter>
          <Routes>
            <Route
              element={
                <AppShell
                  settings={settings}
                  onToggleSidebar={() => updateSettings({ sidebarCollapsed: !settings.sidebarCollapsed })}
                />
              }
            >
              <Route index element={<ChatPage settings={settings} session={session} />} />
              <Route
                path="settings"
                element={
                  <SettingsPage
                    settings={settings}
                    onUpdate={updateSettings}
                    onReset={resetSettings}
                  />
                }
              />
              <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
          </Routes>
        </BrowserRouter>
        <InstallPrompt />
      </ToastProvider>
    </ErrorBoundary>
  )
}
