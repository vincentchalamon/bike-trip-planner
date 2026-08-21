/// <reference types="jest" />
import i18n from '../i18n';
import { formatFreshness } from './freshness';

const NOW = Date.parse('2026-08-21T12:00:00Z');
const ago = (ms: number): number => NOW - ms;

const HOUR = 3_600_000;
const DAY = 86_400_000;

describe('formatFreshness', () => {
  beforeAll(async () => {
    await i18n.changeLanguage('fr');
  });

  it('reads "à l\'instant" under an hour', () => {
    expect(formatFreshness(i18n.t, ago(0), NOW)).toBe("à l'instant");
    expect(formatFreshness(i18n.t, ago(59 * 60_000), NOW)).toBe("à l'instant");
  });

  it('reads "il y a N h" within the day', () => {
    expect(formatFreshness(i18n.t, ago(HOUR), NOW)).toBe('il y a 1 h');
    expect(formatFreshness(i18n.t, ago(5 * HOUR), NOW)).toBe('il y a 5 h');
    expect(formatFreshness(i18n.t, ago(23 * HOUR), NOW)).toBe('il y a 23 h');
  });

  it('reads "hier" the day after', () => {
    expect(formatFreshness(i18n.t, ago(DAY), NOW)).toBe('hier');
    expect(formatFreshness(i18n.t, ago(2 * DAY - 1), NOW)).toBe('hier');
  });

  it('reads "il y a N j" beyond', () => {
    expect(formatFreshness(i18n.t, ago(2 * DAY), NOW)).toBe('il y a 2 j');
    expect(formatFreshness(i18n.t, ago(9 * DAY), NOW)).toBe('il y a 9 j');
  });

  it('treats a future last-sync (clock skew) as "à l\'instant"', () => {
    expect(formatFreshness(i18n.t, NOW + HOUR, NOW)).toBe("à l'instant");
  });

  it('localises in English', async () => {
    await i18n.changeLanguage('en');
    expect(formatFreshness(i18n.t, ago(3 * HOUR), NOW)).toBe('3h ago');
    expect(formatFreshness(i18n.t, ago(2 * DAY), NOW)).toBe('2 days ago');
    await i18n.changeLanguage('fr');
  });
});
