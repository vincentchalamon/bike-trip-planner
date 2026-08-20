import { useEffect, type ReactNode } from 'react';
import { resolveTheme } from './theme';
import { ThemeContext, useColorScheme } from './context';
import { useAppFonts } from './fonts';
import { useThemePrefs } from '../store/theme-prefs';

export function ThemeProvider({ children }: { children: ReactNode }) {
  const scheme = useColorScheme();
  const mode = useThemePrefs((s) => s.mode);
  const load = useThemePrefs((s) => s.load);
  // Hydrate the persisted override once; until then `mode` is 'system', so the
  // OS scheme still drives the theme (no flash of the wrong palette).
  useEffect(() => void load(), [load]);
  // Load webfonts once at the root; children render immediately either way and
  // fall back to the system font until the webfonts are ready.
  useAppFonts();
  // `system` defers to the OS scheme; an explicit override wins over it.
  const effective = mode === 'system' ? scheme : mode;
  return <ThemeContext.Provider value={resolveTheme(effective)}>{children}</ThemeContext.Provider>;
}
