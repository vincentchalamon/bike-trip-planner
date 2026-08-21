/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';

// Mock the module with literals (no requireActual) so the real client.ts — and
// its native auth/notifications import chain — never loads under jest.
jest.mock('../api/nearby-pois', () => ({
  MAX_RADIUS_METERS: 20_000,
  searchNearbyPois: jest.fn(),
}));

import {
  searchNearbyPois,
  MAX_RADIUS_METERS,
  type NearbyPoiSearchResult,
} from '../api/nearby-pois';
import { useInRideSearch, type UseInRideSearch } from './use-in-ride-search';

const mockSearch = searchNearbyPois as jest.MockedFunction<typeof searchNearbyPois>;
const POS = { lat: 45, lon: 6 };

function makeOk(over: Record<string, unknown> = {}): NearbyPoiSearchResult {
  return {
    status: 'ok',
    data: {
      '@id': '/trips/t1/nearby-pois',
      '@type': 'Trip.NearbyPoiSearchResponse',
      tripId: 't1',
      category: 'water',
      radiusMeters: 3000,
      totalFound: 1,
      capReached: false,
      outOfCoverage: false,
      pois: [{ name: 'Fontaine', category: 'water', lat: 45, lon: 6, distance_m: 120, deeplink: 'https://m' }],
      ...over,
    },
  };
}

// Minimal renderHook on react-test-renderer (the mobile convention, no RTL).
function renderHook(): { result: { current: UseInRideSearch }; unmount: () => void } {
  const result = { current: undefined as unknown as UseInRideSearch };
  function Probe() {
    result.current = useInRideSearch('t1');
    return null;
  }
  let renderer!: ReturnType<typeof TestRenderer.create>;
  act(() => {
    renderer = TestRenderer.create(createElement(Probe));
  });
  return { result, unmount: () => act(() => renderer.unmount()) };
}

beforeEach(() => jest.clearAllMocks());

describe('useInRideSearch (#1150)', () => {
  it('runs a search and exposes the recap on success', async () => {
    mockSearch.mockResolvedValue(makeOk());
    const { result, unmount } = renderHook();

    await act(async () => {
      result.current.search('water', POS);
    });

    expect(mockSearch).toHaveBeenCalledWith('t1', {
      category: 'water',
      position: POS,
      radiusMeters: null,
      stageDay: null,
    });
    expect(result.current.isSearching).toBe(false);
    expect(result.current.errorKey).toBeNull();
    expect(result.current.recap?.totalFound).toBe(1);
    expect(result.current.recap?.pois).toHaveLength(1);
    unmount();
  });

  it('offers widen when nothing was found, replaying with a doubled radius', async () => {
    mockSearch.mockResolvedValue(
      makeOk({ totalFound: 0, capReached: false, radiusMeters: 3000, pois: [] }),
    );
    const { result, unmount } = renderHook();

    await act(async () => {
      result.current.search('water', POS);
    });
    expect(result.current.canWiden).toBe(true);

    await act(async () => {
      result.current.widen(POS);
    });
    // Second call replays the same category at 2x the radius (3000 -> 6000).
    expect(mockSearch).toHaveBeenLastCalledWith('t1', {
      category: 'water',
      position: POS,
      radiusMeters: 6000,
      stageDay: null,
    });
    unmount();
  });

  it('caps the widened radius at MAX_RADIUS_METERS then abandons (no-op)', async () => {
    mockSearch.mockResolvedValue(makeOk({ totalFound: 0, radiusMeters: MAX_RADIUS_METERS, pois: [] }));
    const { result, unmount } = renderHook();

    await act(async () => {
      result.current.search('water', POS);
    });
    // Already at the ceiling: widen is disabled and does nothing.
    expect(result.current.canWiden).toBe(false);
    await act(async () => {
      result.current.widen(POS);
    });
    expect(mockSearch).toHaveBeenCalledTimes(1);
    unmount();
  });

  it('does not widen when the candidate cap was reached (radius widening is a no-op)', async () => {
    mockSearch.mockResolvedValue(makeOk({ totalFound: 50, capReached: true, pois: [] }));
    const { result, unmount } = renderHook();

    await act(async () => {
      result.current.search('food', POS);
    });
    expect(result.current.canWiden).toBe(false);
    unmount();
  });

  it('drops a stale response when a newer search resolves first (sequence guard)', async () => {
    let resolveFirst!: (v: NearbyPoiSearchResult) => void;
    const first = new Promise<NearbyPoiSearchResult>((resolve) => {
      resolveFirst = resolve;
    });
    mockSearch.mockReturnValueOnce(first);
    mockSearch.mockResolvedValueOnce(makeOk({ category: 'food', totalFound: 2 }));

    const { result, unmount } = renderHook();

    // Tap 'water' (stays pending), then 'food' (resolves immediately).
    act(() => {
      result.current.search('water', POS);
    });
    await act(async () => {
      result.current.search('food', POS);
    });

    expect(result.current.recap?.category).toBe('food');

    // The stale 'water' response resolves after — must not override the recap.
    await act(async () => {
      resolveFirst(makeOk({ category: 'water', totalFound: 1 }));
    });

    expect(result.current.recap?.category).toBe('food');
    unmount();
  });

  it('surfaces the rate-limit error key on a 429', async () => {
    mockSearch.mockResolvedValue({ status: 'rate_limited' });
    const { result, unmount } = renderHook();

    await act(async () => {
      result.current.search('water', POS);
    });
    expect(result.current.errorKey).toBe('errorRateLimit');
    expect(result.current.recap).toBeNull();
    unmount();
  });

  it('surfaces the network error key when the request never reaches the backend', async () => {
    mockSearch.mockResolvedValue({ status: 'network' });
    const { result, unmount } = renderHook();

    await act(async () => {
      result.current.search('water', POS);
    });
    expect(result.current.errorKey).toBe('errorNetwork');
    unmount();
  });
});
