import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.mmu.chatbot',
  appName: 'MMU Chatbot',
  webDir: 'dist',
  plugins: {
    SplashScreen: {
      launchShowDuration: 2000,
      launchAutoHide: true,
      backgroundColor: "#0f0f13",
      androidSplashResourceName: "splash",
      androidScaleType: "CENTER_CROP",
      showSpinner: true,
      androidSpinnerStyle: "large",
      iosSpinnerStyle: "small",
      spinnerColor: "#667eea",
      splashFullScreen: true,
      splashImmersive: true,
      layoutName: "launch_screen",
      useDialog: true,
    },
    StatusBar: {
      style: "DARK",
      backgroundColor: "#0f0f13",
    }
  },
  server: {
    androidScheme: 'https',
    cleartext: true // Required for local LAN development (e.g. http://172.20.10.4:8000)
  }
};

export default config;
