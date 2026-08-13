import { type ReactNode } from 'react';
import { resolveTheme } from './theme';
import { ThemeContext, useColorScheme } from './context';
import { useAppFonts } from './fonts';

export function ThemeProvider({ children }: { children: ReactNode }) {
  const scheme = useColorScheme();
  // Load webfonts once at the root; children render immediately either way and
  // fall back to the system font until the webfonts are ready.
  useAppFonts();
  return <ThemeContext.Provider value={resolveTheme(scheme)}>{children}</ThemeContext.Provider>;
}
