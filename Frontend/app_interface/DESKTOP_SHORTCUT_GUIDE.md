# Desktop Shortcut Download Feature

## What Was Added

A new section in the Settings page that allows users to download desktop shortcuts for quick access to the MMU Campus Assistant app.

## How It Works

### Windows (.url file)
1. Click "Download" under Windows Shortcut
2. A `.url` file is downloaded
3. Double-click the file to open the app in your default browser
4. Optionally, copy it to your desktop or pin to taskbar

**File Format:**
```
[InternetShortcut]
URL=http://localhost:5173
IconIndex=0
IconFile=http://localhost:5173/icons/icon.svg
```

### Linux (.desktop file)
1. Click "Download" under Linux Shortcut
2. A `.desktop` file is downloaded
3. **Make it executable:** `chmod +x ~/Downloads/MMU-Chat.desktop`
4. Move to desktop: `mv ~/Downloads/MMU-Chat.desktop ~/Desktop/`
5. Or move to applications: `mv ~/Downloads/MMU-Chat.desktop ~/.local/share/applications/`

**File Format (freedesktop.org standard):**
```
[Desktop Entry]
Type=Application
Version=1.0
Name=MMU Campus Assistant
Comment=MMU Campus AI Chatbot Assistant
Exec=xdg-open http://localhost:5173
Icon=http://localhost:5173/icons/icon.svg
Terminal=false
Categories=Education;Network;
StartupNotify=true
```

## Features

✅ **Platform Detection**: Separate downloads for Windows and Linux  
✅ **Automatic URL**: Uses current `window.location.origin`  
✅ **Icon Support**: Links to app icon  
✅ **Standards Compliant**: 
   - Windows: Internet Shortcut format
   - Linux: FreeDesktop.org Desktop Entry Specification 1.0

## User Instructions

### For Windows Users:
1. Go to Settings page
2. Scroll to "Desktop Shortcut" section
3. Click "Download" under Windows Shortcut
4. Open Downloads folder
5. Double-click `MMU-Chat.url` to test
6. Right-click and "Copy" → paste to Desktop for permanent shortcut
7. (Optional) Right-click → Pin to taskbar for quick access

### For Linux Users:
1. Go to Settings page
2. Scroll to "Desktop Shortcut" section
3. Click "Download" under Linux Shortcut
4. Open Terminal:
   ```bash
   cd ~/Downloads
   chmod +x MMU-Chat.desktop
   ./MMU-Chat.desktop  # Test it
   ```
5. **For Desktop Icon:**
   ```bash
   mv MMU-Chat.desktop ~/Desktop/
   ```
6. **For Application Menu:**
   ```bash
   mv MMU-Chat.desktop ~/.local/share/applications/
   ```
7. **For System-wide (requires sudo):**
   ```bash
   sudo mv MMU-Chat.desktop /usr/share/applications/
   ```

### For macOS Users:
macOS doesn't support `.url` files the same way. Users can:
1. Open Safari/Chrome
2. Navigate to the app
3. Drag the URL from address bar to Desktop
4. Or use bookmarks and add to Dock

## Customization

To change the app name, icon, or URL:

Edit in `SettingsPage.tsx`:
```typescript
const appUrl = window.location.origin  // Change this for production
const appName = 'MMU Campus Assistant'  // Change app name
const iconUrl = `${appUrl}/icons/icon.svg`  // Change icon path
```

## Production Deployment

When deploying to production, the shortcuts will automatically use the production URL because of `window.location.origin`.

Example production URLs:
- `https://chat.mmu.ac.ug`
- `https://mmu-chat.app.com`

## Troubleshooting

### Linux: "Untrusted application launcher"
```bash
# Make the file executable
chmod +x MMU-Chat.desktop

# Trust the file (Ubuntu/GNOME)
gio set ~/Desktop/MMU-Chat.desktop metadata::trusted true
```

### Windows: Icon not showing
- Windows may cache icons
- Try restarting Windows Explorer: `Ctrl+Shift+Esc` → Restart "Windows Explorer"

### Icon shows broken image
- Ensure the app is running when clicking the shortcut
- Check if `/icons/icon.svg` exists in your `public` folder

## Future Enhancements

Possible additions:
- [ ] Add macOS `.webloc` file support
- [ ] Include app icon as embedded data URI
- [ ] Add "Install as PWA" button for supported browsers
- [ ] Create installer packages (.msi for Windows, .deb/.rpm for Linux)
- [ ] Add QR code for mobile installation
