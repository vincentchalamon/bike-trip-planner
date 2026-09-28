/// <reference types="jest" />
import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import { runInsertRestDay, runUpdateDates, runUpdatePacing } from './mutations';
import { runDeleteStage } from './delete-stage';
import { useTripStore, useTripTemporalStore } from './trip-store';
import { useOfflineStore } from './offline-store';

jest.mock('./trip-cache', () => ({ deleteTripCache: jest.fn() }));

jest.mock('../api/trips', () => ({
  updateTripConfig: jest.fn(),
  insertRestDay: jest.fn(),
  deleteStage: jest.fn(),
}));

import { updateTripConfig, insertRestDay, deleteStage } from '../api/trips';

const mock = <T extends (...args: never[]) => unknown>(fn: T) =>
  fn as unknown as jest.MockedFunction<T>;

const P = { lat: 0, lon: 0, ele: 0 };

function stage(overrides: Partial<StageData> = {}): StageData {
  const dayNumber = overrides.dayNumber ?? 1;
  return {
    id: `stage-${dayNumber}`,
    dayNumber,
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
const temporal = () => useTripTemporalStore.getState();

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

describe('roadbook undo/redo (#1178)', () => {
  it('undo reverts a structural edit and redo re-applies it', async () => {
    mock(insertRestDay).mockResolvedValue({ ok: true, status: 202 });

    expect(temporal().canUndo).toBe(false);

    await runInsertRestDay('t1', 0, ctx(), jest.fn());
    expect(useTripStore.getState().stages).toHaveLength(3);
    expect(temporal().canUndo).toBe(true);
    expect(temporal().canRedo).toBe(false);

    temporal().undo();
    expect(useTripStore.getState().stages).toHaveLength(2);
    expect(temporal().canUndo).toBe(false);
    expect(temporal().canRedo).toBe(true);

    temporal().redo();
    expect(useTripStore.getState().stages).toHaveLength(3);
    expect(temporal().canUndo).toBe(true);
    expect(temporal().canRedo).toBe(false);
  });

  it('undo restores the pre-edit dates', async () => {
    mock(updateTripConfig).mockResolvedValue({ ok: true, status: 202 });

    await runUpdateDates('t1', '2026-09-01', '2026-09-10', ctx(), jest.fn());
    expect(useTripStore.getState().startDate).toBe('2026-09-01');

    temporal().undo();
    expect(useTripStore.getState().startDate).toBe('2026-08-01');
    expect(useTripStore.getState().endDate).toBe('2026-08-02');
  });

  it('is a no-op with empty history', () => {
    expect(temporal().canUndo).toBe(false);
    temporal().undo();
    expect(useTripStore.getState().stages).toHaveLength(2);
    expect(temporal().canUndo).toBe(false);
    expect(temporal().canRedo).toBe(false);
  });

  it('leaves no phantom undo entry when the mutation fails and rolls back', async () => {
    mock(deleteStage).mockResolvedValue({ ok: false, status: 422 });

    const ok = await runDeleteStage('t1', 0, ctx(), jest.fn());
    expect(ok).toBe(false);
    // Rolled back to the original two stages...
    expect(useTripStore.getState().stages).toHaveLength(2);
    // ...and the pushed snapshot was popped, so nothing is undoable.
    expect(temporal().canUndo).toBe(false);
  });

  it('does not record undo history for a mutation refused offline', async () => {
    useOfflineStore.setState({ isOnline: false });
    const onFailure = jest.fn();

    const ok = await runInsertRestDay('t1', 0, ctx(), onFailure);
    expect(ok).toBe(false);
    expect(onFailure).toHaveBeenCalledWith('offline');
    expect(insertRestDay).not.toHaveBeenCalled();
    expect(temporal().canUndo).toBe(false);
  });

  it('a refused edit withdraws its own entry, not the one recorded after it', async () => {
    // Every config PATCH waits until the test settles it, so the three edits
    // below overlap and land in the order chosen here.
    const settle: ((status: number) => void)[] = [];
    mock(updateTripConfig).mockImplementation(
      () =>
        new Promise((resolve) =>
          settle.push((status) => resolve({ ok: status < 400, status })),
        ),
    );
    useTripStore.setState({ fatigueFactor: 0.8 });
    const pacing = (fatigueFactor: number) => ({
      fatigueFactor,
      elevationPenalty: 100,
      maxDistancePerDay: 80,
      averageSpeed: 15,
      ebikeMode: false,
      departureHour: 8,
    });

    const dates = runUpdateDates('t1', '2026-09-01', '2026-09-10', ctx(), jest.fn());
    const first = runUpdatePacing('t1', pacing(0.9), ctx(), jest.fn());
    const second = runUpdatePacing('t1', pacing(1), ctx(), jest.fn());
    // Both pacing edits are accepted, then the dates are refused.
    settle[1]!(202);
    settle[2]!(202);
    await Promise.all([first, second]);
    settle[0]!(422);
    await dates;

    expect(useTripStore.getState()).toMatchObject({
      startDate: '2026-08-01',
      fatigueFactor: 1,
    });
    temporal().undo();
    expect(useTripStore.getState()).toMatchObject({
      startDate: '2026-08-01',
      endDate: '2026-08-02',
      fatigueFactor: 0.9,
    });
    temporal().undo();
    expect(useTripStore.getState()).toMatchObject({
      startDate: '2026-08-01',
      fatigueFactor: 0.8,
    });
    expect(temporal().canUndo).toBe(false);
  });
});

describe('a refused edit reverts only itself (overlapping edits)', () => {
  // Each mocked request waits until the test settles it, in the order it chooses.
  function deferred<T extends (...args: never[]) => unknown>(fn: T) {
    const settle: ((status: number) => void)[] = [];
    mock(fn).mockImplementation(
      () =>
        new Promise((resolve) =>
          settle.push((status) => resolve({ ok: status < 400, status })),
        ) as ReturnType<T>,
    );
    return settle;
  }

  it('keeps dates changed while a refused date change was in flight', async () => {
    const settle = deferred(updateTripConfig);

    const refused = runUpdateDates('t1', '2026-09-01', '2026-09-10', ctx(), jest.fn());
    const accepted = runUpdateDates('t1', '2026-10-01', '2026-10-10', ctx(), jest.fn());
    settle[1]!(202);
    await accepted;
    settle[0]!(422);
    await refused;

    expect(useTripStore.getState()).toMatchObject({
      startDate: '2026-10-01',
      endDate: '2026-10-10',
    });
    temporal().undo();
    expect(useTripStore.getState()).toMatchObject({
      startDate: '2026-08-01',
      endDate: '2026-08-02',
    });
    expect(temporal().canUndo).toBe(false);
  });

  it('keeps pacing committed while a refused pacing edit was in flight, the shared value included', async () => {
    const settle = deferred(updateTripConfig);
    useTripStore.setState({ fatigueFactor: 0.8, maxDistancePerDay: 80 });
    const pacing = (maxDistancePerDay: number) => ({
      fatigueFactor: 0.9,
      elevationPenalty: 100,
      maxDistancePerDay,
      averageSpeed: 15,
      ebikeMode: false,
      departureHour: 8,
    });

    const refused = runUpdatePacing('t1', pacing(80), ctx(), jest.fn());
    const accepted = runUpdatePacing('t1', pacing(120), ctx(), jest.fn());
    settle[1]!(202);
    await accepted;
    settle[0]!(422);
    await refused;

    // Both set the fatigue to 0.9; the newer edit owns it, so it is not reverted.
    expect(useTripStore.getState()).toMatchObject({
      fatigueFactor: 0.9,
      maxDistancePerDay: 120,
    });
  });

  it('keeps a rest day accepted while a refused deletion was in flight', async () => {
    const deletions = deferred(deleteStage);
    const restDays = deferred(insertRestDay);

    const refused = runDeleteStage('t1', 1, ctx(), jest.fn());
    const accepted = runInsertRestDay('t1', 0, ctx(), jest.fn());
    const restDayId = useTripStore.getState().stages[1]!.id;
    restDays[0]!(202);
    await accepted;
    deletions[0]!(422);
    await refused;

    const state = useTripStore.getState();
    expect(state.stages.map((s) => s.id)).toEqual(['stage-1', restDayId, 'stage-2']);
    expect(state.stages.map((s) => s.dayNumber)).toEqual([1, 2, 3]);
    expect(state.endDate).toBe('2026-08-03');
    // Undoing the accepted rest day must not bring the refused deletion back.
    temporal().undo();
    expect(useTripStore.getState().stages.map((s) => s.id)).toEqual([
      'stage-1',
      'stage-2',
    ]);
    expect(temporal().canUndo).toBe(false);
  });

  it('keeps a stage deleted while a refused rest-day insertion was in flight', async () => {
    const deletions = deferred(deleteStage);
    const restDays = deferred(insertRestDay);

    const refused = runInsertRestDay('t1', 0, ctx(), jest.fn());
    const accepted = runDeleteStage('t1', 2, ctx(), jest.fn());
    deletions[0]!(202);
    await accepted;
    restDays[0]!(422);
    await refused;

    expect(useTripStore.getState().stages.map((s) => s.id)).toEqual(['stage-1']);
  });
});

