import { createContext, useContext } from 'react';
import { useColorScheme as useRNColorScheme } from 'react-native';
import { resolveTheme, type ColorScheme, type Theme } from './theme';

export const ThemeContext = createContext<Theme | null>(null);

// Non-null color scheme, defaulting to light (RN returns null on unknown).
export function useColorScheme(): ColorScheme {
  return useRNColorScheme() === 'dark' ? 'dark' : 'light';
}

// Reads the current theme. Falls back to the light theme when used outside a
// provider (keeps primitives renderable in isolation, e.g. under tests).
export function useTheme(): Theme {
  return useContext(ThemeContext) ?? resolveTheme('light');
}
