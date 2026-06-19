import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'
import path from 'path'
import os from 'os'

// ── Helper: get LAN IP ────────────────────────────────────
function getLANIP(): string | null {
  const nets = os.networkInterfaces()
  for (const name of Object.keys(nets)) {
    for (const net of (nets[name] ?? [])) {
      if (net.family === 'IPv4' && !net.internal) return net.address
    }
  }
  return null
}

import fs from 'fs'

// ── Vite plugin: print QR to terminal + expose /api/__network_url ─
function mmuDevPlugin(): Plugin {
  let networkURL = ''

  return {
    name: 'mmu-dev',
    apply: 'serve',       // only in dev mode

    configureServer(server) {
      // 1. Expose network URL as a JSON endpoint the browser can fetch
      server.middlewares.use('/__network_url', (_req, res) => {
        res.setHeader('Content-Type', 'application/json')
        res.setHeader('Access-Control-Allow-Origin', '*')
        res.end(JSON.stringify({ url: networkURL }))
      })

      // 2. Serve the actual Android APK if requested
      server.middlewares.use('/download-apk', (_req, res) => {
        const apkPath = path.resolve(__dirname, 'android/app/build/outputs/apk/debug/app-debug.apk')
        if (fs.existsSync(apkPath)) {
          const stat = fs.statSync(apkPath)
          res.setHeader('Content-Length', stat.size)
          res.setHeader('Content-Type', 'application/vnd.android.package-archive')
          res.setHeader('Content-Disposition', 'attachment; filename=mmu-chatbot.apk')
          const readStream = fs.createReadStream(apkPath)
          readStream.pipe(res)
        } else {
          res.statusCode = 404
          res.end('APK not found. Please build the Android app first using: cd android && ./gradlew assembleDebug')
        }
      })

      // Print QR after server is listening
      server.httpServer?.once('listening', async () => {
        const port  = 5174
        const lanIP = getLANIP()
        networkURL  = lanIP ? `http://${lanIP}:${port}` : `http://localhost:${port}`
        const local = `http://localhost:${port}`

        // Dynamic import — qrcode-terminal is CJS, lands on .default in ESM
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const qrMod = await import('qrcode-terminal' as any).catch(() => null) as any
        const qr = qrMod?.default ?? qrMod

        const line  = '─'.repeat(54)
        const reset = '\x1b[0m'
        const bold  = '\x1b[1m'
        const cyan  = '\x1b[36m'
        const green = '\x1b[32m'
        const yellow = '\x1b[33m'
        const dim   = '\x1b[2m'

        console.log(`\n${dim}  ${line}${reset}`)
        console.log(`${bold}${cyan}  MMU Campus Assistant (Native Mobile Mode)${reset}`)
        console.log(`${dim}  ${line}${reset}`)
        console.log(`  ${dim}Web App: ${reset}  ${local}`)

        const apkPath = path.resolve(__dirname, 'android/app/build/outputs/apk/debug/app-debug.apk')
        const apkExists = fs.existsSync(apkPath)

        if (lanIP) {
          if (apkExists) {
            console.log(`  ${dim}APK File:${reset}  ${green}${bold}${networkURL}/download-apk${reset}  ← scan below to INSTALL!`)
          } else {
            console.log(`  ${yellow}⚠ APK not built yet.${reset} Run \`cd android && ./gradlew assembleDebug\``)
          }
        }
        console.log(`${dim}  ${line}${reset}\n`)

        if (qr && apkExists && lanIP) {
          console.log(`  📱 Scan to download & install Native Android App:\n`)
          qr.generate(`${networkURL}/download-apk`, { small: true }, (code: string) => {
            console.log(code.split('\n').map((l: string) => '  ' + l).join('\n'))
          })
          console.log(`\n  ${dim}1. Scan with camera, it will download 'mmu-chatbot.apk'${reset}`)
          console.log(`  ${dim}2. Tap the downloaded file to install it.${reset}`)
          console.log(`  ${dim}3. Open from home screen (No browser chrome!)${reset}\n`)
          console.log(`${dim}  ${line}${reset}\n`)
        } else if (qr && !apkExists && lanIP) {
          console.log(`  📱 Scan to open Web App (PWA) in browser:\n`)
          qr.generate(networkURL, { small: true }, (code: string) => {
            console.log(code.split('\n').map((l: string) => '  ' + l).join('\n'))
          })
        }
      })
    },
  }
}

// ── Main config ───────────────────────────────────────────
export default defineConfig({
  plugins: [
    react(),
    mmuDevPlugin(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['icons/*.png', 'icons/*.svg'],
      manifest: {
        name: 'MMU Campus Assistant',
        short_name: 'MMU Chat',
        description: 'Mountains of the Moon University AI Chatbot App',
        theme_color: '#667eea',
        background_color: '#0f0f13',
        display: 'standalone',
        orientation: 'portrait-primary',
        scope: '/',
        start_url: '/',
        icons: [
          { src: '/icons/icon.svg', sizes: 'any', type: 'image/svg+xml' },
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
        runtimeCaching: [
          {
            urlPattern: /^https:\/\/fonts\.googleapis\.com\/.*/i,
            handler: 'CacheFirst',
            options: { cacheName: 'google-fonts-cache', expiration: { maxEntries: 10, maxAgeSeconds: 60 * 60 * 24 * 365 } },
          },
        ],
      },
    }),
  ],
  resolve: {
    alias: { '@': path.resolve(__dirname, 'src') },
  },
  server: {
    host: true,
    port: 5174,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
        secure: false,
      },
    },
  },
})
