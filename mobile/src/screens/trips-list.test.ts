/// <reference types="jest" />
import type { Theme } from '../theme';
import { statusOf, badgeColors } from './trips-list';

// A fake theme whose every color maps to its own token name, so an assertion can
// name the exact token a branch selected.
const theme = {
  colors: new Proxy({}, { get: (_t, key) => String(key) }),
} as unknown as Theme;

describe('statusOf', () => {
  it('defaults a missing status to draft', () => {
    expect(statusOf({ status: undefined })).toBe('draft');
    expect(statusOf({ status: null } as never)).toBe('draft');
  });

  it('passes a present status through', () => {
    expect(statusOf({ status: 'analyzed' })).toBe('analyzed');
  });
});

describe('badgeColors', () => {
  it('uses the amber/accent tokens while analysing', () => {
    expect(badgeColors(theme, 'analyzing')).toEqual({
      bg: 'accentSoft',
      fg: 'accentInk',
      border: 'accentBrand',
    });
  });

  it('uses the green success tokens once analysed', () => {
    expect(badgeColors(theme, 'analyzed')).toEqual({
      bg: 'successSoft',
      fg: 'successInk',
      border: 'successBorder',
    });
  });

  it('uses neutral tokens for a draft', () => {
    expect(badgeColors(theme, 'draft')).toEqual({
      bg: 'muted',
      fg: 'mutedForeground',
      border: 'border',
    });
  });
});
