/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import { useStageDetail } from './use-stage-detail';
import { useTripStore } from '../store/trip-store';

jest.mock('../api/trips', () => ({ fetchStageDetail: jest.fn() }));
import { fetchStageDetail } from '../api/trips';
const mockDetail = fetchStageDetail as jest.MockedFunction<typeof fetchStageDetail>;

const A = { lat: 1, lon: 1, ele: 0 };
const B = { lat: 2, lon: 2, ele: 0 };

function stageData(overrides: Partial<StageData> = {}): StageData {
  const dayNumber = overrides.dayNumber ?? 1;
  return {
    id: `stage-${dayNumber}`,
    dayNumber,
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

const store = () => useTripStore.getState();

// Probes must be torn down between tests: the store is a global singleton, so a
// still-mounted effect would re-fire on the next test's setState.
let renderers: ReturnType<typeof TestRenderer.create>[] = [];
async function render(index: number): Promise<void> {
  function Probe() {
    useStageDetail(index);
    return null;
  }
  await act(async () => {
    renderers.push(TestRenderer.create(createElement(Probe)));
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  useTripStore.getState().reset();
});

afterEach(() => {
  act(() => renderers.forEach((r) => r.unmount()));
  renderers = [];
});

describe('useStageDetail (ADR-057)', () => {
  it('fetches one stage detail and merges only its geometry', async () => {
    useTripStore.setState({
      tripId: 't1',
      stages: [stageData({ dayNumber: 1 }), stageData({ dayNumber: 2 })],
      loading: false,
    });
    mockDetail.mockResolvedValue({
      dayNumber: 2,
      geometry: [{ lat: 48, lon: 2, ele: 100 }],
    } as never);

    await render(1);

    expect(mockDetail).toHaveBeenCalledWith('t1', 'stage-2');
    expect(store().stages[0]!.geometry).toEqual([]);
    expect(store().stages[1]!.geometry).toEqual([{ lat: 48, lon: 2, ele: 100 }]);
  });

  // The geometry used to be applied by position after the await, so a move in
  // between landed stage 2's line on whichever stage then sat at index 1.
  it('applies the geometry to the stage it was fetched for, not to the position', async () => {
    useTripStore.setState({
      tripId: 't1',
      stages: [stageData({ dayNumber: 1 }), stageData({ dayNumber: 2 })],
      loading: false,
    });
    let resolve: (value: unknown) => void = () => undefined;
    mockDetail
      .mockReturnValueOnce(
        new Promise((r) => {
          resolve = r;
        }) as never,
      )
      .mockReturnValue(new Promise(() => undefined) as never);

    await render(1);
    expect(mockDetail).toHaveBeenCalledWith('t1', 'stage-2');
    // Optimistic move: stage-2 goes first, stage-1 now sits at index 1.
    act(() => store().moveStageOptimistic(1, 0));
    await act(async () => {
      resolve({ geometry: [{ lat: 48, lon: 2, ele: 100 }] });
    });

    const byId = Object.fromEntries(store().stages.map((s) => [s.id, s.geometry]));
    expect(byId['stage-1']).toEqual([]);
  });

  it('fetches again, and drops the stale answer, when another stage takes the index', async () => {
    useTripStore.setState({
      tripId: 't1',
      stages: [stageData({ dayNumber: 1 }), stageData({ dayNumber: 2 })],
      loading: false,
    });
    let release!: (value: unknown) => void;
    mockDetail.mockReturnValueOnce(new Promise((resolve) => (release = resolve)) as never);
    mockDetail.mockResolvedValueOnce({ geometry: [B] } as never);
    await render(0);
    expect(mockDetail).toHaveBeenLastCalledWith('t1', 'stage-1');

    // Stage 1 deleted: stage 2 is now at index 0, still without geometry.
    await act(async () => {
      useTripStore.setState({ stages: [stageData({ dayNumber: 2 })] });
    });
    expect(mockDetail).toHaveBeenLastCalledWith('t1', 'stage-2');
    expect(store().stages[0]!.geometry).toEqual([B]);

    await act(async () => release({ geometry: [A] }));
    expect(store().stages[0]!.geometry).toEqual([B]);
  });

  it('does not fetch when the stage already has geometry', async () => {
    useTripStore.setState({
      tripId: 't1',
      stages: [stageData({ geometry: [{ lat: 48, lon: 2, ele: 100 }] })],
      loading: false,
    });
    await render(0);
    expect(mockDetail).not.toHaveBeenCalled();
  });

  it('does not fetch without a trip id', async () => {
    useTripStore.setState({ stages: [stageData()], loading: false });
    await render(0);
    expect(mockDetail).not.toHaveBeenCalled();
  });

  it('warns (but never throws) when the fetch fails', async () => {
    const warn = jest.spyOn(console, 'warn').mockImplementation(() => undefined);
    useTripStore.setState({
      tripId: 't1',
      stages: [stageData()],
      loading: false,
    });
    mockDetail.mockRejectedValue(new Error('boom'));

    await render(0);

    expect(warn).toHaveBeenCalled();
    expect(store().stages[0]!.geometry).toEqual([]);
    warn.mockRestore();
  });
});
