/// <reference types="jest" />
import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import {
  runAddManualAccommodation,
  runAddPoiWaypoint,
  runAddStage,
  runAnalyze,
  runApplyBatch,
  runDeleteTrip,
  runDeselectAccommodation,
  runDuplicateTrip,
  runInsertRestDay,
  runMoveStage,
  runScanAccommodations,
  runSelectAccommodation,
  runUpdateAccommodationTypes,
  runUpdateDates,
  runUpdatePacing,
  runUpdateStageDistance,
  runUpdateTitle,
} from './mutations';
import { useTripStore } from './trip-store';
import { useOfflineStore } from './offline-store';

jest.mock('./trip-cache', () => ({
  deleteTripCache: jest.fn(),
}));

jest.mock('../api/trips', () => ({
  updateTripConfig: jest.fn(),
  createStage: jest.fn(),
  updateStageDistance: jest.fn(),
  moveStage: jest.fn(),
  insertRestDay: jest.fn(),
  setStageAccommodation: jest.fn(),
  addManualAccommodation: jest.fn(),
  addPoiWaypoint: jest.fn(),
  scanAccommodations: jest.fn(),
  applyBatchRecompute: jest.fn(),
  analyzeTrip: jest.fn(),
  duplicateTrip: jest.fn(),
  deleteTrip: jest.fn(),
}));

import {
  updateTripConfig,
  createStage,
  updateStageDistance,
  moveStage,
  insertRestDay,
  setStageAccommodation,
  addManualAccommodation,
  addPoiWaypoint,
  scanAccommodations,
  applyBatchRecompute,
  analyzeTrip,
  duplicateTrip,
  deleteTrip,
} from '../api/trips';
import { deleteTripCache } from './trip-cache';

const mock = <T extends (...args: never[]) => unknown>(fn: T) =>
  fn as unknown as jest.MockedFunction<T>;

const P = { lat: 0, lon: 0, ele: 0 };

function stage(overrides: Partial<StageData> = {}): StageData {
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 0,
    elevationLoss: 0,
    startPoint: P,
    endPoint: { lat: 1, lon: 1, ele: 0 },
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

const ctx = () => useTripStore.getState();

beforeEach(() => {
  jest.clearAllMocks();
  useTripStore.getState().reset();
  useOfflineStore.setState({ isOnline: true, apiReachable: true });
  useTripStore.setState({
    stages: [stage({ dayNumber: 1 }), stage({ dayNumber: 2 })],
    isLocked: false,
    outOfZone: false,
    startDate: '2026-08-01',
    endDate: '2026-08-02',
    loading: false,
  });
});

describe('runUpdateDates (config, non-routing)', () => {
  it('applies optimistically and sends the full pacing patch on success', async () => {
    mock(updateTripConfig).mockResolvedValue({ ok: true, status: 202 });

    const ok = await runUpdateDates(
      't1',
      '2026-09-01',
      '2026-09-10',
      ctx(),
      jest.fn(),
    );

    expect(ok).toBe(true);
    expect(useTripStore.getState().startDate).toBe('2026-09-01');
    const body = mock(updateTripConfig).mock.calls[0]![1];
    expect(body.startDate).toBe('2026-09-01');
    expect(body.fatigueFactor).toBe(useTripStore.getState().fatigueFactor);
  });

  it('rolls back the optimistic dates and reports "validation" on 422', async () => {
    mock(updateTripConfig).mockResolvedValue({ ok: false, status: 422 });
    const onFailure = jest.fn();

    const ok = await runUpdateDates('t1', '2026-09-01', null, ctx(), onFailure);

    expect(ok).toBe(false);
    expect(useTripStore.getState().startDate).toBe('2026-08-01');
    expect(onFailure).toHaveBeenCalledWith('validation');
  });
});

describe('runAddStage (routing) — gating', () => {
  it('is refused out of zone without an optimistic edit or API call', async () => {
    useTripStore.setState({ outOfZone: true });
    const onFailure = jest.fn();

    const ok = await runAddStage('t1', 0, ctx(), onFailure);

    expect(ok).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('out_of_zone');
    expect(useTripStore.getState().stages).toHaveLength(2);
    expect(createStage).not.toHaveBeenCalled();
  });

  it('inserts optimistically then calls the API in zone', async () => {
    mock(createStage).mockResolvedValue({ ok: true, status: 202 });

    const ok = await runAddStage('t1', 0, ctx(), jest.fn());

    expect(ok).toBe(true);
    expect(useTripStore.getState().stages).toHaveLength(3);
    expect(createStage).toHaveBeenCalledWith(
      't1',
      expect.objectContaining({ position: 1 }),
    );
  });
});

describe('runAddManualAccommodation (routing)', () => {
  const input = {
    name: 'Chez Test',
    address: '10 rue de la Paix, Paris',
    priceTotal: 90,
    url: 'https://booking.example/x',
  };

  it('calls the API with the full input when allowed', async () => {
    mock(addManualAccommodation).mockResolvedValue({ ok: true, status: 202 });

    const ok = await runAddManualAccommodation('t1', 0, input, ctx(), jest.fn());

    expect(ok).toBe(true);
    expect(addManualAccommodation).toHaveBeenCalledWith('t1', 0, input);
  });

  it('is refused out of zone without an API call (reroute gate)', async () => {
    useTripStore.setState({ outOfZone: true });
    const onFailure = jest.fn();

    const ok = await runAddManualAccommodation('t1', 0, input, ctx(), onFailure);

    expect(ok).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('out_of_zone');
    expect(addManualAccommodation).not.toHaveBeenCalled();
  });

  it('reports "validation" on a 422 geocoding failure', async () => {
    mock(addManualAccommodation).mockResolvedValue({ ok: false, status: 422 });
    const onFailure = jest.fn();

    const ok = await runAddManualAccommodation('t1', 0, input, ctx(), onFailure);

    expect(ok).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('validation');
  });
});

describe('runUpdateStageDistance (routing) — gating branches', () => {
  it('is refused when the trip is locked (423)', async () => {
    useTripStore.setState({ isLocked: true });
    const onFailure = jest.fn();

    const ok = await runUpdateStageDistance('t1', 0, 42, ctx(), onFailure);

    expect(ok).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('locked');
    expect(updateStageDistance).not.toHaveBeenCalled();
  });

  it('is refused while offline', async () => {
    useOfflineStore.setState({ isOnline: false });
    const onFailure = jest.fn();

    const ok = await runUpdateStageDistance('t1', 0, 42, ctx(), onFailure);

    expect(ok).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('offline');
    expect(updateStageDistance).not.toHaveBeenCalled();
  });

  it('calls the API when allowed', async () => {
    mock(updateStageDistance).mockResolvedValue({ ok: true, status: 202 });
    const ok = await runUpdateStageDistance('t1', 0, 42, ctx(), jest.fn());
    expect(ok).toBe(true);
    expect(updateStageDistance).toHaveBeenCalledWith('t1', 0, 42);
  });
});

describe('runSelectAccommodation', () => {
  beforeEach(() => {
    useTripStore.setState({
      stages: [
        stage({ accommodations: [{ name: 'Gite', lat: 9, lon: 9 } as never] }),
        stage(),
      ],
    });
  });

  it('selects optimistically then calls the API in zone', async () => {
    mock(setStageAccommodation).mockResolvedValue({ ok: true, status: 202 });

    const ok = await runSelectAccommodation('t1', 0, 0, ctx(), jest.fn());

    expect(ok).toBe(true);
    expect(setStageAccommodation).toHaveBeenCalledWith('t1', 0, 9, 9);
    expect(
      useTripStore.getState().stages[0]!.selectedAccommodation,
    ).not.toBeNull();
  });

  it('rolls back and reports "conflict" on 409 (stale list)', async () => {
    mock(setStageAccommodation).mockResolvedValue({ ok: false, status: 409 });
    mock(scanAccommodations).mockResolvedValue({ ok: true, status: 202 });
    const onFailure = jest.fn();

    const ok = await runSelectAccommodation('t1', 0, 0, ctx(), onFailure);

    expect(ok).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('conflict');
    expect(useTripStore.getState().stages[0]!.selectedAccommodation).toBeNull();
  });

  it('re-scans this stage at the default radius on a 409 stale list', async () => {
    mock(setStageAccommodation).mockResolvedValue({ ok: false, status: 409 });
    mock(scanAccommodations).mockResolvedValue({ ok: true, status: 202 });

    await runSelectAccommodation('t1', 0, 0, ctx(), jest.fn());

    // DEFAULT_ACCOMMODATION_RADIUS_KM = 5, stageIndex = 0.
    expect(scanAccommodations).toHaveBeenCalledWith('t1', 5, 0);
  });

  it('does NOT re-scan when the select succeeds', async () => {
    mock(setStageAccommodation).mockResolvedValue({ ok: true, status: 202 });

    await runSelectAccommodation('t1', 0, 0, ctx(), jest.fn());

    expect(scanAccommodations).not.toHaveBeenCalled();
  });

  it('reports "network" when the 409 re-scan itself fails (not swallowed)', async () => {
    mock(setStageAccommodation).mockResolvedValue({ ok: false, status: 409 });
    mock(scanAccommodations).mockRejectedValue(new Error('offline'));
    const onFailure = jest.fn();

    await runSelectAccommodation('t1', 0, 0, ctx(), onFailure);
    // Let the fire-and-forget re-scan rejection settle.
    await new Promise<void>((resolve) => setImmediate(() => resolve()));

    expect(onFailure).toHaveBeenCalledWith('conflict');
    expect(onFailure).toHaveBeenCalledWith('network');
  });
});

describe('config runners (non-routing) — allowed out of zone + rollback', () => {
  beforeEach(() => useTripStore.setState({ outOfZone: true }));

  const pacing = {
    fatigueFactor: 0.75,
    elevationPenalty: 60,
    maxDistancePerDay: 95,
    averageSpeed: 21,
    ebikeMode: true,
    departureHour: 7,
  };

  it('runUpdatePacing applies optimistically out of zone', async () => {
    mock(updateTripConfig).mockResolvedValue({ ok: true, status: 202 });
    expect(await runUpdatePacing('t1', pacing, ctx(), jest.fn())).toBe(true);
    expect(useTripStore.getState().averageSpeed).toBe(21);
    expect(updateTripConfig).toHaveBeenCalled();
  });

  it('runUpdatePacing rolls back pacing on 422', async () => {
    const before = useTripStore.getState().averageSpeed;
    mock(updateTripConfig).mockResolvedValue({ ok: false, status: 422 });
    const onFailure = jest.fn();
    expect(await runUpdatePacing('t1', pacing, ctx(), onFailure)).toBe(false);
    expect(useTripStore.getState().averageSpeed).toBe(before);
    expect(onFailure).toHaveBeenCalledWith('validation');
  });

  it('runUpdateTitle applies then rolls back on failure', async () => {
    mock(updateTripConfig).mockResolvedValue({ ok: true, status: 202 });
    expect(await runUpdateTitle('t1', 'Corse', ctx(), jest.fn())).toBe(true);
    expect(useTripStore.getState().title).toBe('Corse');

    const before = useTripStore.getState().title;
    mock(updateTripConfig).mockResolvedValue({ ok: false, status: 422 });
    expect(await runUpdateTitle('t1', 'Alpes', ctx(), jest.fn())).toBe(false);
    expect(useTripStore.getState().title).toBe(before);
  });

  it('runUpdateAccommodationTypes applies then rolls back on failure', async () => {
    mock(updateTripConfig).mockResolvedValue({ ok: true, status: 202 });
    expect(
      await runUpdateAccommodationTypes('t1', ['hotel'], ctx(), jest.fn()),
    ).toBe(true);
    expect(useTripStore.getState().enabledAccommodationTypes).toEqual(['hotel']);

    const before = useTripStore.getState().enabledAccommodationTypes;
    mock(updateTripConfig).mockResolvedValue({ ok: false, status: 422 });
    expect(
      await runUpdateAccommodationTypes('t1', ['rental'], ctx(), jest.fn()),
    ).toBe(false);
    expect(useTripStore.getState().enabledAccommodationTypes).toEqual(before);
  });

  it('runInsertRestDay inserts optimistically out of zone and rolls back on failure', async () => {
    mock(insertRestDay).mockResolvedValueOnce({ ok: true, status: 202 });
    expect(await runInsertRestDay('t1', 0, ctx(), jest.fn())).toBe(true);
    expect(useTripStore.getState().stages).toHaveLength(3);
    expect(insertRestDay).toHaveBeenCalledWith('t1', 0);

    mock(insertRestDay).mockResolvedValueOnce({ ok: false, status: 409 });
    expect(await runInsertRestDay('t1', 0, ctx(), jest.fn())).toBe(false);
    expect(useTripStore.getState().stages).toHaveLength(3); // rolled back to pre-call
  });
});

describe('routing runners are refused out of zone (requiresRouting flag)', () => {
  beforeEach(() => useTripStore.setState({ outOfZone: true }));

  it('runMoveStage', async () => {
    const onFailure = jest.fn();
    expect(await runMoveStage('t1', 0, 1, ctx(), onFailure)).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('out_of_zone');
    expect(moveStage).not.toHaveBeenCalled();
    expect(useTripStore.getState().stages).toHaveLength(2);
  });

  it('runDeselectAccommodation', async () => {
    const onFailure = jest.fn();
    expect(await runDeselectAccommodation('t1', 0, ctx(), onFailure)).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('out_of_zone');
    expect(setStageAccommodation).not.toHaveBeenCalled();
  });

  it('runAddPoiWaypoint', async () => {
    const onFailure = jest.fn();
    expect(await runAddPoiWaypoint('t1', 0, 1, 2, ctx(), onFailure)).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('out_of_zone');
    expect(addPoiWaypoint).not.toHaveBeenCalled();
  });
});

describe('runMoveStage (routing) — optimistic + rollback', () => {
  beforeEach(() => {
    useTripStore.setState({
      stages: [
        stage({ dayNumber: 1, distance: 10 }),
        stage({ dayNumber: 2, distance: 20 }),
      ],
    });
  });

  it('moves optimistically then calls the API', async () => {
    mock(moveStage).mockResolvedValue({ ok: true, status: 202 });
    expect(await runMoveStage('t1', 1, 0, ctx(), jest.fn())).toBe(true);
    expect(useTripStore.getState().stages.map((s) => s.distance)).toEqual([
      20, 10,
    ]);
    expect(moveStage).toHaveBeenCalledWith('t1', 1, 0);
  });

  it('rolls back the stage order on 409', async () => {
    const before = useTripStore.getState().stages.map((s) => s.distance);
    mock(moveStage).mockResolvedValue({ ok: false, status: 409 });
    const onFailure = jest.fn();
    expect(await runMoveStage('t1', 1, 0, ctx(), onFailure)).toBe(false);
    expect(useTripStore.getState().stages.map((s) => s.distance)).toEqual(before);
    expect(onFailure).toHaveBeenCalledWith('conflict');
  });
});

describe('runDeselectAccommodation (routing) — optimistic + rollback', () => {
  const acc = { name: 'Gite', lat: 9, lon: 9 } as never;

  beforeEach(() => {
    useTripStore.setState({
      stages: [stage({ selectedAccommodation: acc }), stage()],
    });
  });

  it('clears the selection optimistically then calls the API with null coords', async () => {
    mock(setStageAccommodation).mockResolvedValue({ ok: true, status: 202 });
    expect(await runDeselectAccommodation('t1', 0, ctx(), jest.fn())).toBe(true);
    expect(useTripStore.getState().stages[0]!.selectedAccommodation).toBeNull();
    expect(setStageAccommodation).toHaveBeenCalledWith('t1', 0, null, null);
  });

  it('rolls back the selection on 409', async () => {
    mock(setStageAccommodation).mockResolvedValue({ ok: false, status: 409 });
    mock(scanAccommodations).mockResolvedValue({ ok: true, status: 202 });
    const onFailure = jest.fn();
    expect(await runDeselectAccommodation('t1', 0, ctx(), onFailure)).toBe(false);
    expect(useTripStore.getState().stages[0]!.selectedAccommodation).toEqual(acc);
    expect(onFailure).toHaveBeenCalledWith('conflict');
  });

  it('re-scans this stage on a 409 stale list', async () => {
    mock(setStageAccommodation).mockResolvedValue({ ok: false, status: 409 });
    mock(scanAccommodations).mockResolvedValue({ ok: true, status: 202 });
    await runDeselectAccommodation('t1', 0, ctx(), jest.fn());
    expect(scanAccommodations).toHaveBeenCalledWith('t1', 5, 0);
  });
});

describe('runAddPoiWaypoint (routing) — calls the API in zone', () => {
  it('adds the waypoint when allowed', async () => {
    mock(addPoiWaypoint).mockResolvedValue({ ok: true, status: 202 });
    expect(await runAddPoiWaypoint('t1', 0, 1.5, 2.5, ctx(), jest.fn())).toBe(
      true,
    );
    expect(addPoiWaypoint).toHaveBeenCalledWith('t1', 0, 1.5, 2.5);
  });
});

describe('runScanAccommodations (non-routing) — allowed out of zone', () => {
  it('calls the API even out of zone (scan does not reroute)', async () => {
    useTripStore.setState({ outOfZone: true });
    mock(scanAccommodations).mockResolvedValue({ ok: true, status: 202 });

    const ok = await runScanAccommodations('t1', 7, 0, ctx(), jest.fn());

    expect(ok).toBe(true);
    expect(scanAccommodations).toHaveBeenCalledWith('t1', 7, 0);
  });
});

describe('runApplyBatch (recompute queue)', () => {
  it('no-ops on an empty queue', async () => {
    const ok = await runApplyBatch('t1', ctx(), jest.fn());
    expect(ok).toBe(false);
    expect(applyBatchRecompute).not.toHaveBeenCalled();
  });

  it('sends the queued modifications and clears the queue on success', async () => {
    useTripStore.setState({
      pendingModifications: [
        { stageIndex: 0, type: 'distance', label: 'd' },
        { stageIndex: null, type: 'pacing', label: 'p' },
      ],
    });
    mock(applyBatchRecompute).mockResolvedValue({ ok: true, status: 202 });

    const ok = await runApplyBatch('t1', ctx(), jest.fn());

    expect(ok).toBe(true);
    expect(applyBatchRecompute).toHaveBeenCalledWith(
      't1',
      expect.arrayContaining([expect.objectContaining({ type: 'distance' })]),
    );
    expect(useTripStore.getState().pendingModifications).toHaveLength(0);
  });
});

describe('runAnalyze / lifecycle', () => {
  it('runAnalyze is refused on a locked trip', async () => {
    useTripStore.setState({ isLocked: true });
    const onFailure = jest.fn();
    const ok = await runAnalyze('t1', ctx(), onFailure);
    expect(ok).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('locked');
    expect(analyzeTrip).not.toHaveBeenCalled();
  });

  it('runDuplicateTrip is allowed on a locked trip and returns the new id', async () => {
    useTripStore.setState({ isLocked: true });
    mock(duplicateTrip).mockResolvedValue('t2');
    const id = await runDuplicateTrip('t1', ctx(), jest.fn());
    expect(id).toBe('t2');
  });

  it('runDuplicateTrip is refused offline', async () => {
    useOfflineStore.setState({ isOnline: false });
    const onFailure = jest.fn();
    const id = await runDuplicateTrip('t1', ctx(), onFailure);
    expect(id).toBeNull();
    expect(onFailure).toHaveBeenCalledWith('offline');
    expect(duplicateTrip).not.toHaveBeenCalled();
  });

  it('runDeleteTrip is refused offline and succeeds online', async () => {
    const onFailure = jest.fn();
    useOfflineStore.setState({ isOnline: false });
    expect(await runDeleteTrip('t1', ctx(), onFailure)).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('offline');

    useOfflineStore.setState({ isOnline: true });
    mock(deleteTrip).mockResolvedValue({ ok: true, status: 204 });
    expect(await runDeleteTrip('t1', ctx(), jest.fn())).toBe(true);
  });

  it('runDuplicateTrip is refused when the API is unreachable while online (#1166)', async () => {
    useOfflineStore.setState({ isOnline: true, apiReachable: false });
    const onFailure = jest.fn();
    const id = await runDuplicateTrip('t1', ctx(), onFailure);
    expect(id).toBeNull();
    expect(onFailure).toHaveBeenCalledWith('api_unavailable');
    expect(duplicateTrip).not.toHaveBeenCalled();
  });

  it('runDeleteTrip is refused when the API is unreachable while online (#1166)', async () => {
    useOfflineStore.setState({ isOnline: true, apiReachable: false });
    const onFailure = jest.fn();
    expect(await runDeleteTrip('t1', ctx(), onFailure)).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('api_unavailable');
    expect(deleteTrip).not.toHaveBeenCalled();
  });

  it('runDeleteTrip evicts the offline cache on successful deletion, not on failure', async () => {
    useOfflineStore.setState({ isOnline: true });

    mock(deleteTrip).mockResolvedValue({ ok: false, status: 404 });
    expect(await runDeleteTrip('t1', ctx(), jest.fn())).toBe(false);
    expect(deleteTripCache).not.toHaveBeenCalled();

    mock(deleteTrip).mockResolvedValue({ ok: true, status: 204 });
    expect(await runDeleteTrip('t1', ctx(), jest.fn())).toBe(true);
    expect(deleteTripCache).toHaveBeenCalledWith('t1');
  });
});
