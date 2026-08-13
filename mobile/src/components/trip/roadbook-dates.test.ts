/// <reference types="jest" />
import {
  formatStageDate,
  isStageToday,
  stageDateFor,
  summaryColorKey,
  todayUtc,
  tripStateFromDates,
} from './roadbook-dates';

describe('stageDateFor', () => {
  it('returns startDate for day 1 and shifts one UTC day per dayNumber', () => {
    expect(stageDateFor('2026-08-13', 1)).toBe('2026-08-13');
    expect(stageDateFor('2026-08-13', 3)).toBe('2026-08-15');
  });

  it('crosses a month boundary in UTC (timezone-stable)', () => {
    expect(stageDateFor('2026-08-31', 2)).toBe('2026-09-01');
  });

  it('returns null without a start date or on garbage', () => {
    expect(stageDateFor(null, 1)).toBeNull();
    expect(stageDateFor('not-a-date', 1)).toBeNull();
  });
});

describe('tripStateFromDates', () => {
  const today = '2026-08-13';

  it('is upcoming when the start is after today', () => {
    expect(tripStateFromDates('2026-08-20', '2026-08-25', today)).toBe('upcoming');
  });

  it('is ongoing when today is within the range (inclusive bounds)', () => {
    expect(tripStateFromDates('2026-08-10', '2026-08-20', today)).toBe('ongoing');
    expect(tripStateFromDates('2026-08-13', '2026-08-13', today)).toBe('ongoing');
  });

  it('is past when the end is before today', () => {
    expect(tripStateFromDates('2026-08-01', '2026-08-05', today)).toBe('past');
  });

  it('is null when either bound is missing', () => {
    expect(tripStateFromDates(null, '2026-08-20', today)).toBeNull();
    expect(tripStateFromDates('2026-08-10', null, today)).toBeNull();
  });
});

describe('isStageToday', () => {
  it('matches on an exact UTC day and rejects otherwise', () => {
    expect(isStageToday('2026-08-13', '2026-08-13')).toBe(true);
    expect(isStageToday('2026-08-14', '2026-08-13')).toBe(false);
    expect(isStageToday(null, '2026-08-13')).toBe(false);
  });
});

describe('summaryColorKey', () => {
  it('maps each lifecycle state to a theme colour key', () => {
    expect(summaryColorKey('ongoing')).toBe('accentBrand');
    expect(summaryColorKey('past')).toBe('mutedForeground');
    expect(summaryColorKey('upcoming')).toBe('foreground');
    expect(summaryColorKey(null)).toBe('foreground');
  });
});

describe('todayUtc', () => {
  it('formats an injected instant as its UTC calendar day', () => {
    // 00:30 Paris on the 14th is still the 13th in UTC — the injected date pins it.
    expect(todayUtc(new Date('2026-08-13T23:30:00Z'))).toBe('2026-08-13');
  });
});

describe('formatStageDate', () => {
  it('formats a UTC day in the given locale without drifting', () => {
    expect(formatStageDate('2026-08-13', 'fr')).toContain('13');
    expect(formatStageDate('2026-08-13', 'en')).toContain('13');
  });
});
