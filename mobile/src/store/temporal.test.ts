/// <reference types="jest" />
import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import { runInsertRestDay, runUpdateDates } from './mutations';
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
});
