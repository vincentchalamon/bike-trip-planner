/// <reference types="jest" />
import { darkColors, fonts, lightColors, radius, spacing } from './tokens';

describe('design tokens', () => {
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
