#!/bin/bash
# ============================================================
# MMU Campus Assistant — Native App Launcher
# Opens the app as a standalone window (no browser chrome)
# ============================================================

APP_URL="http://localhost:5174"
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)/Frontend/app_interface"
BACKEND_DIR="$(cd "$(dirname "$0")/.." && pwd)/backend"

# ── 1. Start backend if not running ──────────────────────
if ! curl -s "$APP_URL" >/dev/null 2>&1 && ! pgrep -f "uvicorn" >/dev/null; then
  echo "▶  Starting backend…"
  cd "$BACKEND_DIR"
  nohup uvicorn main:app --host 0.0.0.0 --port 8000 > /tmp/mmu-backend.log 2>&1 &
  sleep 2
fi

# ── 2. Start Vite dev server if not already running ──────
if ! curl -s "$APP_URL" >/dev/null 2>&1; then
  echo "▶  Starting frontend dev server…"
  cd "$APP_DIR"
  nohup npm run dev > /tmp/mmu-frontend.log 2>&1 &
  # Wait up to 10s for it to be ready
  for i in $(seq 1 10); do
    sleep 1
    if curl -s "$APP_URL" >/dev/null 2>&1; then break; fi
    echo "   Waiting for server… ($i/10)"
  done
fi

echo "▶  Launching MMU Chat app…"

# ── 3. Open in app mode (standalone window, no browser UI) ─
# Try browsers in order of preference

ICON_PATH="$(cd "$(dirname "$0")/.." && pwd)/Frontend/app_interface/public/icons/icon.svg"

if command -v google-chrome &>/dev/null; then
  google-chrome \
    --app="$APP_URL" \
    --new-window \
    --no-default-browser-check \
    --disable-extensions \
    --window-size=420,820 \
    --window-position=100,50 \
    2>/dev/null &

elif command -v chromium-browser &>/dev/null; then
  chromium-browser \
    --app="$APP_URL" \
    --new-window \
    --disable-extensions \
    --window-size=420,820 \
    2>/dev/null &

elif command -v chromium &>/dev/null; then
  chromium \
    --app="$APP_URL" \
    --new-window \
    --disable-extensions \
    --window-size=420,820 \
    2>/dev/null &

elif command -v microsoft-edge &>/dev/null; then
  microsoft-edge \
    --app="$APP_URL" \
    --new-window \
    --window-size=420,820 \
    2>/dev/null &

else
  # Fallback: open in default browser
  echo "⚠  Chrome/Chromium not found — opening in default browser"
  xdg-open "$APP_URL" 2>/dev/null || open "$APP_URL" 2>/dev/null
fi

echo "✅  MMU Chat launched at $APP_URL"
