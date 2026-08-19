/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import { useTripRoute } from './use-trip-route';
import { useTripStore } from '../store/trip-store';

jest.mock('../api/trips', () => ({ fetchTripRoute: jest.fn() }));
import { fetchTripRoute } from '../api/trips';
const mockRoute = fetchTripRoute as jest.MockedFunction<typeof fetchTripRoute>;

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

// Minimal renderHook on react-test-renderer (the mobile convention, no RTL).
// The store is a global singleton, so mounted probes must be torn down between
// tests — else a still-mounted effect re-fires on the next test's setState.
let renderers: ReturnType<typeof TestRenderer.create>[] = [];
async function render(options?: { enabled?: boolean }): Promise<void> {
  function Probe() {
    useTripRoute(options);
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

describe('useTripRoute (ADR-057)', () => {
  it('fetches the route and merges geometry into the store', async () => {
    useTripStore.setState({
      tripId: 't1',
      stages: [stageData({ dayNumber: 1 })],
      geometryLoaded: false,
      loading: false,
    });
    mockRoute.mockResolvedValue({
      id: 't1',
      stages: [{ dayNumber: 1, geometry: [{ lat: 48, lon: 2, ele: 100 }] }],
    } as never);

    await render();

    expect(mockRoute).toHaveBeenCalledWith('t1');
    expect(store().geometryLoaded).toBe(true);
    expect(store().stages[0]!.geometry).toEqual([{ lat: 48, lon: 2, ele: 100 }]);
  });

  it('does not fetch when disabled', async () => {
    useTripStore.setState({ tripId: 't1', geometryLoaded: false, loading: false });
    await render({ enabled: false });
    expect(mockRoute).not.toHaveBeenCalled();
  });

  it('does not fetch when the geometry is already loaded', async () => {
    useTripStore.setState({ tripId: 't1', geometryLoaded: true, loading: false });
    await render();
    expect(mockRoute).not.toHaveBeenCalled();
  });

  it('does not fetch without a trip id', async () => {
    await render();
    expect(mockRoute).not.toHaveBeenCalled();
  });

  it('warns (but never throws) when the fetch fails', async () => {
    const warn = jest.spyOn(console, 'warn').mockImplementation(() => undefined);
    useTripStore.setState({ tripId: 't1', geometryLoaded: false, loading: false });
    mockRoute.mockRejectedValue(new Error('boom'));

    await render();

    expect(warn).toHaveBeenCalled();
    expect(store().geometryLoaded).toBe(false);
    warn.mockRestore();
  });
});
