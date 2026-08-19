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

    expect(mockDetail).toHaveBeenCalledWith('t1', 1);
    expect(store().stages[0]!.geometry).toEqual([]);
    expect(store().stages[1]!.geometry).toEqual([{ lat: 48, lon: 2, ele: 100 }]);
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
