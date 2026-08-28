/// <reference types="jest" />
import { darkColors, fonts, lightColors, radius, spacing } from './tokens';

// WCAG 2.x relative luminance / contrast ratio (formulas straight from the
// spec) — used below to guard the a11y-load-bearing token pairs (#1233)
// against silent regressions, without pulling in an external lib.
function channelLuminance(c: number): number {
  const s = c / 255;
  return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
}
function relativeLuminance(hex: string): number {
  const h = hex.replace('#', '');
  const r = parseInt(h.slice(0, 2), 16);
  const g = parseInt(h.slice(2, 4), 16);
  const b = parseInt(h.slice(4, 6), 16);
  return 0.2126 * channelLuminance(r) + 0.7152 * channelLuminance(g) + 0.0722 * channelLuminance(b);
}
function contrastRatio(a: string, b: string): number {
  const l1 = relativeLuminance(a);
  const l2 = relativeLuminance(b);
  const [lighter, darker] = l1 > l2 ? [l1, l2] : [l2, l1];
  return (lighter + 0.05) / (darker + 0.05);
}

describe('design tokens', () => {
  it('meets WCAG AA contrast for the a11y-load-bearing pairs (#1233)', () => {
    // Non-text UI components (icon-only controls, e.g. DateField's clear
    // button): WCAG 1.4.11 minimum 3:1.
    expect(contrastRatio(lightColors.mutedIcon, lightColors.background)).toBeGreaterThanOrEqual(3);
    expect(contrastRatio(darkColors.mutedIcon, darkColors.background)).toBeGreaterThanOrEqual(3);
    // Normal text: WCAG 1.4.3 minimum 4.5:1 (destructive is used as text —
    // ListRow danger rows, Input/ErrorState error copy).
    expect(contrastRatio(lightColors.destructive, lightColors.background)).toBeGreaterThanOrEqual(4.5);
    expect(contrastRatio(darkColors.destructive, darkColors.background)).toBeGreaterThanOrEqual(4.5);
    // Button's outlineForest variant text/border (create.tsx GPX import).
    expect(contrastRatio(lightColors.forestText, lightColors.background)).toBeGreaterThanOrEqual(4.5);
    expect(contrastRatio(darkColors.forestText, darkColors.background)).toBeGreaterThanOrEqual(4.5);
  });


  it('mirrors the web brand + surface hexes in both schemes', () => {
    expect(lightColors.brand).toBe('#a8561a');
    expect(darkColors.brand).toBe('#e08040');
    expect(lightColors.surface).toBe('#faf7f0');
    expect(darkColors.surface).toBe('#1a1814');
  });

  it('exposes the same colour keys for light and dark', () => {
    expect(Object.keys(darkColors).sort()).toEqual(Object.keys(lightColors).sort());
  });

  it('carries the spacing scale from globals.css', () => {
    expect(Object.values(spacing)).toEqual([6, 8, 12, 16, 22, 28, 36, 48, 64]);
  });

  it('uses a 10px base radius', () => {
    expect(radius.lg).toBe(10);
  });

  it('names the Fraunces / Inter Tight / JetBrains Mono families', () => {
    expect(fonts.serif).toContain('Fraunces');
    expect(fonts.sans).toContain('InterTight');
    expect(fonts.mono).toContain('JetBrainsMono');
  });
});
