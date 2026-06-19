#!/bin/bash
# MMU Chat Desktop Launcher Installer
# This script installs the MMU Campus Assistant desktop launcher

set -e

echo "🎓 MMU Campus Assistant - Desktop Launcher Installer"
echo "=================================================="
echo ""

# Get the network IP (or use the one provided)
NETWORK_IP="${1:-172.20.10.4:5173}"

# Create the .desktop file
DESKTOP_FILE="MMU-Chat.desktop"

cat > "$DESKTOP_FILE" << EOF
[Desktop Entry]
Version=1.0
Type=Application
Name=MMU Campus Assistant
Comment=MMU AI Chatbot - Campus Information Assistant
Exec=xdg-open http://${NETWORK_IP}
Icon=web-browser
Terminal=false
Categories=Network;Education;WebBrowser;
StartupNotify=true
EOF

echo "✅ Created $DESKTOP_FILE"
echo ""

# Make it executable
chmod +x "$DESKTOP_FILE"
echo "✅ Made executable"
echo ""

# Ask user where to install
echo "Where would you like to install the launcher?"
echo "1) Desktop (~/Desktop/) - Quick access icon"
echo "2) Applications Menu (~/.local/share/applications/) - Shows in app launcher"
echo "3) Both Desktop and Applications Menu"
echo "4) Just download (current directory)"
echo ""
read -p "Enter choice (1-4): " choice

case $choice in
    1)
        cp "$DESKTOP_FILE" ~/Desktop/
        echo "✅ Installed to Desktop"
        echo "📍 Location: ~/Desktop/$DESKTOP_FILE"
        ;;
    2)
        mkdir -p ~/.local/share/applications
        cp "$DESKTOP_FILE" ~/.local/share/applications/
        echo "✅ Installed to Applications Menu"
        echo "📍 Location: ~/.local/share/applications/$DESKTOP_FILE"
        ;;
    3)
        cp "$DESKTOP_FILE" ~/Desktop/
        mkdir -p ~/.local/share/applications
        cp "$DESKTOP_FILE" ~/.local/share/applications/
        echo "✅ Installed to Desktop and Applications Menu"
        echo "📍 Desktop: ~/Desktop/$DESKTOP_FILE"
        echo "📍 Apps: ~/.local/share/applications/$DESKTOP_FILE"
        ;;
    4)
        echo "✅ File ready in current directory"
        echo "📍 Location: $(pwd)/$DESKTOP_FILE"
        ;;
    *)
        echo "❌ Invalid choice"
        exit 1
        ;;
esac

echo ""
echo "🎉 Installation complete!"
echo ""
echo "📝 To use:"
echo "   - Double-click the icon to launch the app"
echo "   - Or search for 'MMU Campus Assistant' in your app launcher"
echo ""
echo "⚙️  To customize:"
echo "   - Edit the .desktop file to change the URL or icon"
echo "   - Run: nano ~/Desktop/$DESKTOP_FILE"
echo ""
echo "🌐 Current URL: http://${NETWORK_IP}"
echo ""
