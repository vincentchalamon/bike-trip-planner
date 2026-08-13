/// <reference types="jest" />
import type { StageData } from '@btp/core';
import { DEFAULT_ACCOMMODATION_RADIUS_KM } from '@btp/core/constants';
import type { TripDetail } from '../api/trips';
import { stageDataFromDetail, useTripStore } from './trip-store';

const A = { lat: 1, lon: 1, ele: 0 };
const B = { lat: 2, lon: 2, ele: 0 };

function apiStage(overrides: Record<string, unknown> = {}) {
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: A,
    endPoint: B,
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
    isRestDay: false,
    ...overrides,
  };
}

function stageData(overrides: Partial<StageData> = {}): StageData {
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: A,
    endPoint: B,
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

function detail(stages: unknown[]): TripDetail {
  return { title: 'Trip', stages } as unknown as TripDetail;
}

beforeEach(() => {
  useTripStore.getState().reset();
});

describe('mobile trip store (thin wrapper composing core reducers, #1014)', () => {
  it('hydrates /detail stages into StageData with client-only defaults', () => {
    useTripStore
      .getState()
      .hydrate('t1', detail([apiStage({ startLabel: 'Paris' })]));
    const s = useTripStore.getState();
    expect(s.tripId).toBe('t1');
    expect(s.title).toBe('Trip');
    expect(s.loading).toBe(false);
    expect(s.stages).toHaveLength(1);
    expect(s.stages[0]!.startLabel).toBe('Paris');
    expect(s.stages[0]!.accommodationSearchRadiusKm).toBe(
      DEFAULT_ACCOMMODATION_RADIUS_KM,
    );
  });

  it('stageDataFromDetail tags persisted alerts with the terrain group', () => {
    const mapped = stageDataFromDetail(
      apiStage({ alerts: [{ type: 'nudge', message: 'x', lat: null, lon: null }] }),
    );
    expect((mapped.alerts[0] as { _group?: string })._group).toBe('terrain');
  });

  it('applyStageUpdate reconciles via core (preserves prev label on a stable endpoint)', () => {
    useTripStore.setState({ stages: [stageData({ endLabel: 'Lyon' })], loading: false });
    useTripStore.getState().applyStageUpdate(0, stageData({ endLabel: null }));
    expect(useTripStore.getState().stages[0]!.endLabel).toBe('Lyon');
  });

  it('applyTripReady reconciles via core (keeps non-empty prev accommodations on a stable endpoint)', () => {
    const acc = { name: 'Gite' } as StageData['accommodations'][number];
    useTripStore.setState({
      stages: [stageData({ accommodations: [acc] })],
      loading: false,
    });
    useTripStore.getState().applyTripReady([stageData({ accommodations: [] })]);
    expect(useTripStore.getState().stages[0]!.accommodations).toEqual([acc]);
  });
});
