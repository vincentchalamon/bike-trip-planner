/// <reference types="jest" />
import { formatTripDateRange } from './dates';

describe('formatTripDateRange', () => {
  it('formats a start→end range (defaults: day start, long month, UTC)', () => {
    expect(formatTripDateRange('2026-08-15', '2026-08-20', 'fr')).toBe('15 → 20 août 2026');
  });

  it('honours separator / month / startStyle options', () => {
    expect(
      formatTripDateRange('2026-08-15', '2026-08-20', 'fr', {
        separator: ' – ',
        month: 'short',
        startStyle: 'dayMonth',
      }),
    ).toBe('15 août – 20 août 2026');
    expect(
      formatTripDateRange('2026-08-15', '2026-08-20', 'fr', { startStyle: 'full' }),
    ).toBe('15 août 2026 → 20 août 2026');
  });

  it('formats a single full date when there is only a start', () => {
    expect(formatTripDateRange('2026-08-20', null, 'fr')).toBe('20 août 2026');
  });

  it('formats the end alone when there is only an end', () => {
    expect(formatTripDateRange(null, '2026-08-20', 'fr')).toBe('20 août 2026');
  });

  it('returns empty string when there is neither start nor end', () => {
    expect(formatTripDateRange(null, null, 'fr')).toBe('');
    expect(formatTripDateRange(undefined, undefined, 'fr')).toBe('');
  });

  it('falls back to the raw start on an unparseable start ISO', () => {
    expect(formatTripDateRange('not-a-date', '2026-08-20', 'fr')).toBe('not-a-date');
  });

  it('formats the start alone when the end ISO is unparseable', () => {
    expect(formatTripDateRange('2026-08-15', 'nope', 'fr')).toBe('15 août 2026');
  });
});
