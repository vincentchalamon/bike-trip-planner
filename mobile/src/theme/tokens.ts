// Design tokens — native mirror of pwa/src/app/globals.css (single source of
// truth on the web). React Native cannot parse oklch(), so the oklch tokens
// from globals.css are pre-converted to sRGB hex here; brand/surface/ink hexes
// are copied verbatim. Keep both palettes in sync with the web on any change.

export interface ThemeColors {
  background: string;
  surface: string;
  foreground: string;
  ink: string;
  card: string;
  cardForeground: string;
  popover: string;
  popoverForeground: string;
  primary: string;
  primaryForeground: string;
  secondary: string;
  secondaryForeground: string;
  muted: string;
  mutedForeground: string;
  mutedIcon: string;
  accent: string;
  accentForeground: string;
  accentBrand: string;
  accentSoft: string;
  accentInk: string;
  destructive: string;
  destructiveForeground: string;
  border: string;
  input: string;
  ring: string;
  brand: string;
  brandLight: string;
  brandHover: string;
  brandFill: string;
  brandFillHover: string;
  // Spike-UX green branded surfaces (fixed in both schemes, like brandFill):
  // the login hero gradient and the roadbook/share summary card. `forest*` and
  // `summary*` are the same green under two names (hero vs card); keep both.
  forest: string;
  forestDeep: string;
  // `forest` as *text/border* (Button's outlineForest variant, #1233 a11y pass):
  // on the dark background `forest` alone reads at ~2.4:1 (fails WCAG AA
  // non-text 3:1 and text 4.5:1). Same value as `forest` in light (already
  // 7:1+), lightened only for dark so the button stays legible without
  // touching the fixed badge/hero surfaces above.
  forestText: string;
  heroForeground: string;
  summary: string;
  summaryEnd: string;
  summaryForeground: string;
  summaryMuted: string;
  // Soft green "analysed" status badge (trips list).
  successSoft: string;
  successInk: string;
  successBorder: string;
}

export const lightColors: ThemeColors = {
  background: '#faf7f0',
  surface: '#faf7f0',
  foreground: '#1a1814',
  ink: '#1a1814',
  card: '#faf7f0',
  cardForeground: '#1a1814',
  popover: '#faf7f0',
  popoverForeground: '#1a1814',
  primary: '#1a1814',
  primaryForeground: '#faf7f0',
  secondary: '#faf2ec',
  secondaryForeground: '#1a1814',
  muted: '#faf2ec',
  mutedForeground: '#755e4c',
  // Darkened from #9da5a7 (2.34:1 on background, fails WCAG 1.4.11's 3:1
  // non-text minimum — e.g. DateField's icon-only clear button) to 3.20:1.
  // In sync with pwa/src/app/globals.css `--muted-icon` (#1233 / #1242).
  mutedIcon: '#858c8e',
  accent: '#faf2ec',
  accentForeground: '#1a1814',
  accentBrand: '#a8561a',
  accentSoft: '#fdf0e6',
  accentInk: '#6b2d00',
  // Darkened from #e7000b (4.46:1 on background, just under WCAG AA's 4.5:1
  // for normal text — ListRow danger rows, Input/ErrorState error text) to
  // 5.32:1; white-on-destructive (Button destructive variant) stays >=4.5:1.
  // In sync with pwa/src/app/globals.css `--destructive` (#1233 / #1242).
  destructive: '#d0000a',
  destructiveForeground: '#ffffff',
  border: '#ecdfd4',
  input: '#ecdfd4',
  ring: '#bc804d',
  brand: '#a8561a',
  brandLight: '#fdf0e6',
  brandHover: '#8c4716',
  brandFill: '#a8561a',
  brandFillHover: '#8c4716',
  forest: '#3e5c3a',
  forestDeep: '#2f4a34',
  forestText: '#3e5c3a',
  heroForeground: '#f5f0e8',
  summary: '#3e5c3a',
  summaryEnd: '#2f4a34',
  summaryForeground: '#f5f0e8',
  summaryMuted: 'rgba(245,240,232,0.72)',
  successSoft: '#eaf4ec',
  successInk: '#1a3d22',
  successBorder: '#cfe6d4',
};

export const darkColors: ThemeColors = {
  background: '#1a1814',
  surface: '#1a1814',
  foreground: '#f5f0e8',
  ink: '#f5f0e8',
  card: '#19120d',
  cardForeground: '#f5f0e8',
  popover: '#19120d',
  popoverForeground: '#f5f0e8',
  primary: '#f5f0e8',
  primaryForeground: '#1a1814',
  secondary: '#2b221a',
  secondaryForeground: '#f5f0e8',
  muted: '#2b221a',
  mutedForeground: '#ab9380',
  mutedIcon: '#6b7275',
  accent: '#2b221a',
  accentForeground: '#f5f0e8',
  accentBrand: '#e08040',
  accentSoft: '#2e1a08',
  accentInk: '#ffd4a8',
  destructive: '#ff6467',
  destructiveForeground: '#1a1814',
  border: 'rgba(255,255,255,0.1)',
  input: 'rgba(255,255,255,0.15)',
  ring: '#9c622f',
  brand: '#e08040',
  brandLight: '#2e1a08',
  brandHover: '#f09050',
  brandFill: '#a8561a',
  brandFillHover: '#8c4716',
  forest: '#3e5c3a',
  forestDeep: '#2f4a34',
  // Lightened for dark-mode legibility (5.49:1 on the dark background vs
  // 2.36:1 for `forest`) — see the ThemeColors comment on `forestText`.
  forestText: '#6f9a68',
  heroForeground: '#f5f0e8',
  summary: '#3e5c3a',
  summaryEnd: '#2f4a34',
  summaryForeground: '#f5f0e8',
  summaryMuted: 'rgba(245,240,232,0.72)',
  successSoft: '#0d2e14',
  successInk: '#b8f0c0',
  successBorder: 'rgba(120,200,140,0.25)',
};

// Spacing scale (px) — mirrors --spacing-* in globals.css.
export const spacing = {
  xs: 6,
  sm: 8,
  md: 12,
  base: 16,
  lg: 22,
  xl: 28,
  '2xl': 36,
  '3xl': 48,
  '4xl': 64,
} as const;

// Corner radius — --radius is 0.625rem (10px); the scale mirrors --radius-*.
export const radius = {
  sm: 6,
  md: 8,
  lg: 10,
  xl: 14,
  '2xl': 18,
  '3xl': 22,
  '4xl': 26,
  full: 9999,
} as const;

// Font family keys — must match the keys loaded by useAppFonts (fonts.ts).
export const fonts = {
  serif: 'Fraunces_600SemiBold',
  sans: 'InterTight_400Regular',
  sansMedium: 'InterTight_500Medium',
  sansSemibold: 'InterTight_600SemiBold',
  mono: 'JetBrainsMono_400Regular',
} as const;

export interface Shadow {
  shadowColor: string;
  shadowOffset: { width: number; height: number };
  shadowOpacity: number;
  shadowRadius: number;
  elevation: number;
}

// Elevation — mirrors --shadow-{soft,medium,strong}. iOS reads shadow*,
// Android reads elevation.
export const shadows: Record<'soft' | 'medium' | 'strong', Shadow> = {
  soft: { shadowColor: '#000', shadowOffset: { width: 0, height: 1 }, shadowOpacity: 0.08, shadowRadius: 3, elevation: 1 },
  medium: { shadowColor: '#000', shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.12, shadowRadius: 12, elevation: 4 },
  strong: { shadowColor: '#000', shadowOffset: { width: 0, height: 8 }, shadowOpacity: 0.16, shadowRadius: 24, elevation: 8 },
};

export type Spacing = typeof spacing;
export type Radius = typeof radius;
export type Fonts = typeof fonts;
