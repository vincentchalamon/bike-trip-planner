import { useEffect } from 'react';
import { Platform } from 'react-native';
import * as NavigationBar from 'expo-navigation-bar';
import type { ColorScheme } from './theme';

// Harmonize the Android system navigation bar with the active theme (#1222):
// otherwise it stays light/white under a dark UI. `dark` = dark bar + light
// content, `light` = light bar + dark content. Needs the expo-navigation-bar
// plugin's `enforceContrast: false` (app.json) to take effect. No-op on iOS or a
// binary built without the native module.
export function useSystemNavigationBar(scheme: ColorScheme): void {
  useEffect(() => {
    if (Platform.OS !== 'android') return;
    try {
      NavigationBar.setStyle(scheme === 'dark' ? 'dark' : 'light');
    } catch {
      // iOS / dev binary without expo-navigation-bar.
    }
  }, [scheme]);
}
