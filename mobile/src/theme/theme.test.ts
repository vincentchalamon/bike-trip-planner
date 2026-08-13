/// <reference types="jest" />
import { darkColors, lightColors } from './tokens';
import { resolveTheme } from './theme';

describe('resolveTheme', () => {
  it('returns the dark palette for the dark scheme', () => {
    expect(resolveTheme('dark').colors).toBe(darkColors);
    expect(resolveTheme('dark').scheme).toBe('dark');
  });

  it('falls back to light for light, null or undefined', () => {
    expect(resolveTheme('light').colors).toBe(lightColors);
    expect(resolveTheme(null).colors).toBe(lightColors);
    expect(resolveTheme(undefined).colors).toBe(lightColors);
  });
});
