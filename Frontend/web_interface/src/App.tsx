import './App.css'
import Chat from './components/Chat'

function App() {
  return (
    <div style={{ maxWidth: 900, margin: '0 auto', padding: 16 }}>
      <h1>University AI Chatbot</h1>
      <p style={{ color: '#6b7280' }}>
        Connected to backend at {import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'}
      </p>
      <Chat />
    </div>
  )
}

export default App
