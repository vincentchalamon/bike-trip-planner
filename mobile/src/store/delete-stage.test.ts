/// <reference types="jest" />
import type { StageData } from '@btp/core';
import { runDeleteStage } from './delete-stage';
import { useTripStore } from './trip-store';

jest.mock('../api/trips', () => ({ deleteStage: jest.fn() }));
import { deleteStage } from '../api/trips';
const mockDelete = deleteStage as jest.MockedFunction<typeof deleteStage>;

const P = { lat: 0, lon: 0, ele: 0 };

function stage(dayNumber: number): StageData {
  return {
    dayNumber,
    distance: 50,
    elevation: 0,
    elevationLoss: 0,
    startPoint: P,
    endPoint: P,
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
  };
}

const noop = jest.fn();

beforeEach(() => {
  jest.clearAllMocks();
  useTripStore.getState().reset();
  useTripStore.setState({
    stages: [stage(1), stage(2), stage(3)],
    isLocked: false,
    loading: false,
  });
});

describe('runDeleteStage optimistic delete (#1015)', () => {
  it('removes the stage optimistically and renumbers days on success', async () => {
    mockDelete.mockResolvedValue({ ok: true, status: 202 });

    await runDeleteStage('t1', 1, useTripStore.getState(), noop);

    const stages = useTripStore.getState().stages;
    expect(stages).toHaveLength(2);
    expect(stages.map((s) => s.dayNumber)).toEqual([1, 2]);
    expect(mockDelete).toHaveBeenCalledWith('t1', 1);
    expect(noop).not.toHaveBeenCalled();
  });

  it('rolls back and reports "error" on a non-423 failure', async () => {
    mockDelete.mockResolvedValue({ ok: false, status: 500 });
    const onFailure = jest.fn();

    await runDeleteStage('t1', 1, useTripStore.getState(), onFailure);

    expect(useTripStore.getState().stages).toHaveLength(3);
    expect(useTripStore.getState().stages.map((s) => s.dayNumber)).toEqual([1, 2, 3]);
    expect(onFailure).toHaveBeenCalledWith('error');
  });

  it('rolls back and reports "locked" on a 423', async () => {
    mockDelete.mockResolvedValue({ ok: false, status: 423 });
    const onFailure = jest.fn();

    await runDeleteStage('t1', 0, useTripStore.getState(), onFailure);

    expect(useTripStore.getState().stages).toHaveLength(3);
    expect(onFailure).toHaveBeenCalledWith('locked');
  });

  it('rolls back and reports "error" when the request throws', async () => {
    mockDelete.mockRejectedValue(new Error('network'));
    const onFailure = jest.fn();

    await runDeleteStage('t1', 2, useTripStore.getState(), onFailure);

    expect(useTripStore.getState().stages).toHaveLength(3);
    expect(onFailure).toHaveBeenCalledWith('error');
  });

  it('does not touch the store or call the API when the trip is locked', async () => {
    useTripStore.setState({ isLocked: true });
    const onFailure = jest.fn();

    await runDeleteStage('t1', 1, useTripStore.getState(), onFailure);

    expect(useTripStore.getState().stages).toHaveLength(3);
    expect(mockDelete).not.toHaveBeenCalled();
    expect(onFailure).toHaveBeenCalledWith('locked');
  });
});
