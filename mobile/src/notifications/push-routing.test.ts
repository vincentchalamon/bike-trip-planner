/// <reference types="jest" />
import { resolvePushRoute } from './push-routing';

describe('resolvePushRoute', () => {
  it('routes a stage-scoped payload to the stage screen', () => {
    expect(resolvePushRoute({ tripId: 't1', stageId: 'abc-123' })).toBe('/trip/t1/stage/abc-123');
  });

  // An identifier the trip no longer holds falls back to the roadbook, where a
  // stale index used to open whichever stage now occupies that position.
  it('falls back to the roadbook rather than guessing, given only a stage', () => {
    expect(resolvePushRoute({ tripId: 't1', stageId: '' })).toBe('/trip/t1');
  });

  it('routes a trip-scoped payload to the roadbook', () => {
    expect(resolvePushRoute({ tripId: 't1', category: 'analysisDone' })).toBe('/trip/t1');
  });

  it('routes a zone-opening announcement to the create tab', () => {
    expect(resolvePushRoute({ category: 'zoneOpening' })).toBe('/(tabs)/create');
  });

  it('never interpolates an id that is not a plain identifier', () => {
    expect(resolvePushRoute({ tripId: '../account/delete' })).toBeNull();
    expect(resolvePushRoute({ tripId: 't1?x=1' })).toBeNull();
    expect(resolvePushRoute({ tripId: 't1', stageId: '../../account' })).toBe('/trip/t1');
    expect(resolvePushRoute({ tripId: 42 as unknown as string })).toBeNull();
  });

  it('returns null when nothing is actionable', () => {
    expect(resolvePushRoute(null)).toBeNull();
    expect(resolvePushRoute(undefined)).toBeNull();
    expect(resolvePushRoute({})).toBeNull();
    expect(resolvePushRoute({ category: 'weatherSafety' })).toBeNull();
    expect(resolvePushRoute({ stageId: 'abc-123' })).toBeNull();
  });
});
