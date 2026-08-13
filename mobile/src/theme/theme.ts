import {
  darkColors,
  fonts,
  lightColors,
  radius,
  shadows,
  spacing,
  type Fonts,
  type Radius,
  type Shadow,
  type Spacing,
  type ThemeColors,
} from './tokens';

export type ColorScheme = 'light' | 'dark';

export interface Theme {
  scheme: ColorScheme;
  colors: ThemeColors;
  spacing: Spacing;
  radius: Radius;
  fonts: Fonts;
  shadows: Record<'soft' | 'medium' | 'strong', Shadow>;
}

const base = { spacing, radius, fonts, shadows } as const;

export const lightTheme: Theme = { scheme: 'light', colors: lightColors, ...base };
export const darkTheme: Theme = { scheme: 'dark', colors: darkColors, ...base };

export function resolveTheme(scheme: ColorScheme | null | undefined): Theme {
  return scheme === 'dark' ? darkTheme : lightTheme;
}
