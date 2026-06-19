# MMU Campus Assistant — React App

A modern, full-page AI chatbot interface for **Mountains of the Moon University**, built as a companion app alongside the existing chatbot widget. Connects to the same FastAPI backend (`/api/v1/`) with no backend changes required.

## Features

| Feature | Detail |
|---|---|
| 🧠 AI Chat | Full-page chat powered by same MMU backend (Ollama + RAG) |
| 📚 Chat History | Conversations persisted in `localStorage` — no login needed |
| 🌗 Dark / Light / System theme | Instant toggle, persisted across sessions |
| 📐 Font size & density | Small/Medium/Large font, Compact/Comfortable/Spacious layout |
| 📱 Fully responsive | Desktop sidebar, mobile bottom nav & slide-out drawer |
| 📲 PWA installable | Add to home screen on Android/iOS/desktop |
| ✨ Markdown rendering | Tables, code blocks with copy button, bold, lists |
| 👍 Reactions | Thumbs up/down + detailed feedback modal |
| 🔄 Regenerate | Re-run any AI response |
| 🆘 Escalate | Forward unanswered questions to MMU staff |
| ⚡ Offline caching | Service worker caches assets for fast reload |

## Running locally

### Prerequisites
- Node.js 20+
- Backend running on port 8000 (`cd backend && uvicorn main:app --reload`)

### Start

```bash
# From project root
cd Frontend/app_interface
npm install        # already done
npm run dev        # http://localhost:5174
```

Or use the helper script (also copies PWA icons):
```bash
bash scripts/start-app.sh
```

### Build for production
```bash
cd Frontend/app_interface
npm run build      # outputs to dist/
```

## Architecture

```
Frontend/app_interface/src/
├── api/client.ts          # Axios API client (same endpoints as web_interface)
├── store/chatStore.ts     # Zustand: conversations + messages (localStorage)
├── hooks/
│   ├── useSettings.ts     # Theme / font / density (localStorage)
│   ├── useSession.ts      # Backend session init + heartbeat
│   └── useChat.ts         # send/regenerate, rate limiting, error handling
├── components/
│   ├── layout/            # AppShell, Sidebar, MobileNav
│   ├── chat/              # ChatInput, MessageBubble, MessageList, TypingIndicator
│   ├── modals/            # EscalateModal, FeedbackModal
│   └── ui/                # Toast, ErrorBoundary, InstallPrompt
└── pages/
    ├── ChatPage.tsx        # Main chat view
    └── SettingsPage.tsx    # All-local settings
```

## Ports

| Service | Port | URL |
|---|---|---|
| Backend (FastAPI) | 8000 | http://localhost:8000 |
| Existing widget | 5173 | http://localhost:5173 |
| **This app** | **5174** | **http://localhost:5174** |

## Settings (all localStorage, no backend)

- **Theme**: Light / Dark / System
- **Font size**: Small (13px) / Medium (14px) / Large (16px)  
- **Density**: Compact / Comfortable / Spacious  
- **Animations**: toggle on/off  
- **Bubble style**: Rounded / Flat  
- **Clear history**: wipes all local conversations  
