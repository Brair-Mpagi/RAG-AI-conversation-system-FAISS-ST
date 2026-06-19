#!/bin/bash
# MMU Chatbot App — Startup script
# Run this from the project root: bash scripts/start-app.sh

set -e

ICON_SRC="/home/bcodz/.gemini/antigravity/brain/5dd18860-bbf0-4b77-a2ce-c41e6f105c39/pwa_icon_1778688589817.png"
ICON_DIR="./Frontend/app_interface/public/icons"

echo "=== MMU Campus Assistant App ==="

# Copy PWA icons if the generated one exists
if [ -f "$ICON_SRC" ]; then
  mkdir -p "$ICON_DIR"
  cp "$ICON_SRC" "$ICON_DIR/icon-512.png"
  # Resize to 192x192 if ImageMagick is available, otherwise copy as-is
  if command -v convert &>/dev/null; then
    convert "$ICON_SRC" -resize 192x192 "$ICON_DIR/icon-192.png"
    echo "✅ PWA icons generated (192px and 512px)"
  else
    cp "$ICON_SRC" "$ICON_DIR/icon-192.png"
    echo "✅ PWA icons copied (install ImageMagick for proper resizing)"
  fi
else
  echo "ℹ️  PNG icon not found — SVG fallback already in place"
fi

echo ""
echo "Starting MMU Chatbot App at http://localhost:5174 ..."
echo "Backend expected at http://localhost:8000"
echo "(Ensure your backend is running first)"
echo ""

cd ./Frontend/app_interface
npm run dev
