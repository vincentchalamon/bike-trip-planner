/// <reference types="jest" />
import type { EventData } from '@btp/core';
import { eventTypeKey, formatEventDateRange, sortEvents } from './event-utils';

function event(overrides: Partial<EventData> = {}): EventData {
  return {
    name: 'Fête',
    type: 'schema:Festival',
    lat: 0,
    lon: 0,
    startDate: '2026-06-01',
    endDate: '2026-06-01',
    distanceToEndPoint: 0,
    source: 'datatourisme',
    ...overrides,
  } as EventData;
}

describe('sortEvents', () => {
  it('orders chronologically by start date without mutating the input', () => {
    const input = [
      event({ name: 'late', startDate: '2026-06-10' }),
      event({ name: 'early', startDate: '2026-06-01' }),
    ];
    const out = sortEvents(input);
    expect(out.map((e) => e.name)).toEqual(['early', 'late']);
    expect(input[0]!.name).toBe('late');
  });
});

describe('eventTypeKey', () => {
  it('maps known type URIs', () => {
    expect(eventTypeKey('schema:Festival')).toBe('festival');
    expect(eventTypeKey('urn:resource:FairOrShow')).toBe('fairOrShow');
  });

  it('returns null for an unknown type', () => {
    expect(eventTypeKey('schema:Unknown')).toBeNull();
  });
});

describe('formatEventDateRange', () => {
  it('shows a single day when start and end match', () => {
    const s = formatEventDateRange('2026-06-01', '2026-06-01', 'fr');
    expect(s).not.toContain('–');
  });

  it('shows a range across two days', () => {
    const s = formatEventDateRange('2026-06-01', '2026-06-03', 'fr');
    expect(s).toContain('–');
  });
});
