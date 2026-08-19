/// <reference types="jest" />
import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
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
    resupply: EMPTY_RESUPPLY,
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
    resupply: EMPTY_RESUPPLY,
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

  it('applyRoute merges the on-demand geometry into the matching stages by dayNumber', () => {
    useTripStore.setState({
      stages: [stageData({ dayNumber: 1 }), stageData({ dayNumber: 2 })],
      geometryLoaded: false,
      loading: false,
    });
    useTripStore.getState().applyRoute({
      id: 't1',
      stages: [{ dayNumber: 2, geometry: [{ lat: 48, lon: 2, ele: 100 }] }],
    } as never);
    const { stages, geometryLoaded } = useTripStore.getState();
    expect(geometryLoaded).toBe(true);
    expect(stages[0]!.geometry).toEqual([]); // day 1 absent from the route payload
    expect(stages[1]!.geometry).toEqual([{ lat: 48, lon: 2, ele: 100 }]);
  });
});

describe('mobile trip store — config + optimistic structural edits (#1031)', () => {
  it('hydrates the editable config slice, outOfZone and lock from /detail', () => {
    useTripStore.getState().hydrate('t1', {
      title: 'Trip',
      stages: [],
      isLocked: true,
      outOfZone: true,
      startDate: '2026-08-01',
      endDate: '2026-08-05',
      fatigueFactor: 0.7,
      maxDistancePerDay: 120,
      enabledAccommodationTypes: ['hotel'],
    } as unknown as TripDetail);
    const s = useTripStore.getState();
    expect(s.isLocked).toBe(true);
    expect(s.outOfZone).toBe(true);
    expect(s.startDate).toBe('2026-08-01');
    expect(s.fatigueFactor).toBe(0.7);
    expect(s.maxDistancePerDay).toBe(120);
    expect(s.enabledAccommodationTypes).toEqual(['hotel']);
  });

  it('insertRestDayOptimistic inserts a rest day, renumbers, extends endDate', () => {
    useTripStore.setState({
      stages: [stageData({ dayNumber: 1 }), stageData({ dayNumber: 2 })],
      startDate: '2026-08-01',
      endDate: '2026-08-02',
      loading: false,
    });
    useTripStore.getState().insertRestDayOptimistic(0);
    const s = useTripStore.getState();
    expect(s.stages.map((x) => x.dayNumber)).toEqual([1, 2, 3]);
    expect(s.stages[1]!.isRestDay).toBe(true);
    expect(s.endDate).toBe('2026-08-03');
  });

  it('insertStageOptimistic splices a placeholder and renumbers', () => {
    useTripStore.setState({
      stages: [stageData({ dayNumber: 1 }), stageData({ dayNumber: 2 })],
      loading: false,
    });
    useTripStore.getState().insertStageOptimistic(0, stageData({ distance: 7 }));
    const s = useTripStore.getState();
    expect(s.stages).toHaveLength(3);
    expect(s.stages[1]!.distance).toBe(7);
    expect(s.stages.map((x) => x.dayNumber)).toEqual([1, 2, 3]);
  });

  it('moveStageOptimistic reorders and renumbers', () => {
    useTripStore.setState({
      stages: [
        stageData({ dayNumber: 1, distance: 10 }),
        stageData({ dayNumber: 2, distance: 20 }),
        stageData({ dayNumber: 3, distance: 30 }),
      ],
      loading: false,
    });
    useTripStore.getState().moveStageOptimistic(2, 0);
    const s = useTripStore.getState();
    expect(s.stages.map((x) => x.distance)).toEqual([30, 10, 20]);
    expect(s.stages.map((x) => x.dayNumber)).toEqual([1, 2, 3]);
  });

  it('selectAccommodationOptimistic pins the acc and shifts endpoints', () => {
    const acc = { name: 'Gite', lat: 9, lon: 9 } as StageData['accommodations'][number];
    useTripStore.setState({
      stages: [
        stageData({ accommodations: [acc] }),
        stageData({ startPoint: { lat: 0, lon: 0, ele: 0 } }),
      ],
      loading: false,
    });
    useTripStore.getState().selectAccommodationOptimistic(0, 0, 1);
    const s = useTripStore.getState();
    expect(s.stages[0]!.selectedAccommodation).toEqual(acc);
    expect(s.stages[0]!.endPoint).toEqual({ lat: 9, lon: 9, ele: 0 });
    expect(s.stages[1]!.startPoint).toEqual({ lat: 9, lon: 9, ele: 0 });
  });

  it('deselectAccommodationOptimistic clears the selection but leaves endPoint for SSE', () => {
    const acc = { name: 'Gite', lat: 9, lon: 9 } as StageData['accommodations'][number];
    useTripStore.setState({
      stages: [
        stageData({
          accommodations: [acc],
          selectedAccommodation: acc,
          endPoint: { lat: 9, lon: 9, ele: 0 },
        }),
      ],
      loading: false,
    });
    useTripStore.getState().deselectAccommodationOptimistic(0);
    const s = useTripStore.getState();
    expect(s.stages[0]!.selectedAccommodation).toBeNull();
    // endPoint stays pinned to the former accommodation until the recompute
    // reconciles it — documented transient behavior, not reverted here.
    expect(s.stages[0]!.endPoint).toEqual({ lat: 9, lon: 9, ele: 0 });
  });

  it('queueModification appends then replaces a duplicate (same type+index)', () => {
    const q = () => useTripStore.getState().queueModification;
    q()({ stageIndex: 0, type: 'distance', label: 'a' });
    q()({ stageIndex: 1, type: 'distance', label: 'b' });
    q()({ stageIndex: 0, type: 'distance', label: 'a2' });
    const mods = useTripStore.getState().pendingModifications;
    expect(mods).toHaveLength(2);
    expect(mods[0]!.label).toBe('a2');
    useTripStore.getState().cancelAllModifications();
    expect(useTripStore.getState().pendingModifications).toHaveLength(0);
  });
});
