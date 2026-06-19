# How to Use Desktop Shortcuts

## Quick Start

### 🪟 Windows Users
1. Open the MMU Chat app
2. Go to **Settings** (gear icon in sidebar)
3. Scroll to **"Desktop Shortcut"** section
4. Click **"Download"** under Windows Shortcut
5. Open your **Downloads** folder
6. **Double-click** `MMU-Chat.url` to test
7. **Drag** the file to your Desktop for permanent access

**Pro Tip:** Right-click the shortcut → "Pin to taskbar" for one-click access!

---

### 🐧 Linux Users
1. Open the MMU Chat app
2. Go to **Settings** (gear icon in sidebar)
3. Scroll to **"Desktop Shortcut"** section
4. Click **"Download"** under Linux Shortcut
5. Open **Terminal** and run:
   ```bash
   cd ~/Downloads
   chmod +x MMU-Chat.desktop
   ```

#### Option A: Desktop Icon
```bash
mv MMU-Chat.desktop ~/Desktop/
```

#### Option B: Application Menu
```bash
mv MMU-Chat.desktop ~/.local/share/applications/
```

#### Option C: System-Wide (All Users)
```bash
sudo mv MMU-Chat.desktop /usr/share/applications/
```

**Troubleshooting:** If you see "Untrusted application launcher":
```bash
gio set ~/Desktop/MMU-Chat.desktop metadata::trusted true
```

---

## What Gets Downloaded?

### Windows (.url file)
A simple text file that tells Windows to open your browser to the app URL.

**Contents:**
```
[InternetShortcut]
URL=http://localhost:5173
IconIndex=0
IconFile=http://localhost:5173/icons/icon.svg
```

### Linux (.desktop file)
A FreeDesktop.org standard file recognized by most Linux desktop environments (GNOME, KDE, XFCE, etc.).

**Contents:**
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

---

## Browser Opening Behavior

The shortcuts will open the app in your **default browser**.

**To change default browser:**
- **Windows**: Settings → Apps → Default apps → Web browser
- **Linux**: `xdg-settings set default-web-browser firefox.desktop`

---

## Production vs Development

**Development** (localhost):
- URL: `http://localhost:5173`
- Only works when dev server is running

**Production** (deployed):
- URL: `https://chat.mmu.ac.ug` (or your domain)
- Works anytime, from anywhere

The shortcut automatically uses the URL where you downloaded it from!

---

## macOS Alternative

macOS doesn't support `.url` files the same way. Instead:

1. **Safari**: Drag URL from address bar to Desktop
2. **Chrome**: Bookmarks → Add to Dock
3. **Firefox**: Right-click page → Create Desktop Shortcut (if available)

Or create a simple script:
```bash
#!/bin/bash
open http://localhost:5173
```
Save as `mmu-chat.command`, make executable: `chmod +x mmu-chat.command`

---

## Security Note

✅ **Safe**: The shortcuts only contain a URL, no executable code  
✅ **Local**: No data is transmitted during download  
✅ **Open Source**: You can view the file contents in any text editor

---

## Need Help?

**Can't download?** 
- Check if pop-up blocker is enabled
- Try a different browser

**Shortcut doesn't work?**
- Ensure the app server is running (dev mode)
- Check if your browser is updated

**Icon not showing?**
- Windows may need a restart
- Linux: Check icon path in the .desktop file

---

## Advanced: Customize Your Shortcut

You can edit the downloaded files in any text editor!

**Change the name:** Edit the file and change `Name=` value  
**Change the icon:** Replace the `Icon=` URL  
**Open in specific browser:** Change `Exec=xdg-open` to `Exec=firefox` (Linux)

---

Made with ❤️ for MMU students and staff
