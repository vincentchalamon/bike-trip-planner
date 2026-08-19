/// <reference types="jest" />
import { resolvePushRoute } from './push-routing';

describe('resolvePushRoute', () => {
  it('routes a stage-scoped payload to the stage screen', () => {
    expect(resolvePushRoute({ tripId: 't1', stageIndex: 3 })).toBe('/trip/t1/stage/3');
    expect(resolvePushRoute({ tripId: 't1', stageIndex: '0' })).toBe('/trip/t1/stage/0');
  });

  it('routes a trip-scoped payload to the roadbook', () => {
    expect(resolvePushRoute({ tripId: 't1', category: 'analysisDone' })).toBe('/trip/t1');
  });

  it('routes a zone-opening announcement to the create tab', () => {
    expect(resolvePushRoute({ category: 'zoneOpening' })).toBe('/(tabs)/create');
  });

  it('returns null when nothing is actionable', () => {
    expect(resolvePushRoute(null)).toBeNull();
    expect(resolvePushRoute(undefined)).toBeNull();
    expect(resolvePushRoute({})).toBeNull();
    expect(resolvePushRoute({ category: 'weatherSafety' })).toBeNull();
    expect(resolvePushRoute({ stageIndex: 2 })).toBeNull();
  });
});
