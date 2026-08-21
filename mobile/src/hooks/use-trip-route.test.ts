/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import { runLoadTripRoute, useTripRoute } from './use-trip-route';
import { useTripStore } from '../store/trip-store';
import { useOfflineStore } from '../store/offline-store';

jest.mock('../api/trips', () => ({ fetchTripRoute: jest.fn() }));
jest.mock('../store/trip-cache', () => ({
  cacheTripRoute: jest.fn(),
  readTripCache: jest.fn(),
}));
import { fetchTripRoute } from '../api/trips';
import { cacheTripRoute, readTripCache } from '../store/trip-cache';
const mockRoute = fetchTripRoute as jest.MockedFunction<typeof fetchTripRoute>;
const mockCacheRoute = cacheTripRoute as jest.MockedFunction<typeof cacheTripRoute>;
const mockReadCache = readTripCache as jest.MockedFunction<typeof readTripCache>;

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
  useOfflineStore.getState().setOnline(true);
  mockReadCache.mockResolvedValue(null);
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

  it('leaves the geometry unloaded (never throws) when the fetch fails with no cache', async () => {
    useTripStore.setState({ tripId: 't1', geometryLoaded: false, loading: false });
    mockRoute.mockRejectedValue(new Error('boom'));

    await render();

    expect(store().geometryLoaded).toBe(false);
  });

  it('caches the route after a successful online fetch (#1147)', async () => {
    useTripStore.setState({ tripId: 't1', geometryLoaded: false, loading: false });
    const route = { id: 't1', stages: [{ dayNumber: 1, geometry: [] }] };
    mockRoute.mockResolvedValue(route as never);

    await render();

    expect(mockCacheRoute).toHaveBeenCalledWith('t1', route);
  });
});

describe('runLoadTripRoute offline cache (#1147)', () => {
  const route = { id: 't1', stages: [{ dayNumber: 1, geometry: [] }] };

  it('serves the cached tracé without hitting the network while offline', async () => {
    useOfflineStore.getState().setOnline(false);
    mockReadCache.mockResolvedValue({ detail: {}, route, syncedAt: 1 } as never);

    const result = await runLoadTripRoute('t1');

    expect(result).toEqual(route);
    expect(mockRoute).not.toHaveBeenCalled();
  });

  it('falls back to the cached tracé when the network fetch fails', async () => {
    mockRoute.mockRejectedValue(new Error('boom'));
    mockReadCache.mockResolvedValue({ detail: {}, route, syncedAt: 1 } as never);

    const result = await runLoadTripRoute('t1');

    expect(result).toEqual(route);
  });
});
