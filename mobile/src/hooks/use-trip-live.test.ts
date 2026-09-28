/// <reference types="jest" />
import { createElement } from 'react';
import { EMPTY_RESUPPLY } from '@btp/core';
import TestRenderer, { act } from 'react-test-renderer';
import type { EnrichedStagePayload, MercureEvent } from '@btp/core/mercure';
import { AppState } from 'react-native';
import { applyResync, runTripLive, useTripLive } from './use-trip-live';
import { useTripStore } from '../store/trip-store';
import { useConnectivity } from '../store/use-connectivity';
import { useDismissedAlerts } from '../store/dismissed-alerts';
import { useOfflineStore } from '../store/offline-store';

jest.mock('../api/trips', () => ({ fetchTripDetail: jest.fn() }));
jest.mock('../api/mercure', () => ({ subscribeToTrip: jest.fn() }));
let mockNetInfoListener:
  | ((state: { isConnected: boolean; isInternetReachable: boolean }) => void)
  | undefined;
jest.mock('@react-native-community/netinfo', () => ({
  __esModule: true,
  default: {
    addEventListener: jest.fn((listener) => {
      mockNetInfoListener = listener;
      return jest.fn();
    }),
  },
}));
jest.mock('../store/trip-cache', () => ({
  cacheTripDetail: jest.fn(),
  readTripCache: jest.fn(),
}));

import { fetchTripDetail } from '../api/trips';
import { subscribeToTrip, type SubscribeOptions } from '../api/mercure';
import { cacheTripDetail, readTripCache } from '../store/trip-cache';

const mockDetail = fetchTripDetail as jest.MockedFunction<
  typeof fetchTripDetail
>;
const mockSubscribe = subscribeToTrip as jest.MockedFunction<
  typeof subscribeToTrip
>;
const mockCache = cacheTripDetail as jest.MockedFunction<
  typeof cacheTripDetail
>;
const mockReadCache = readTripCache as jest.MockedFunction<
  typeof readTripCache
>;

const A = { lat: 1, lon: 1, ele: 0 };
const B = { lat: 2, lon: 2, ele: 0 };

function apiStage(overrides: Record<string, unknown> = {}) {
  const dayNumber = (overrides.dayNumber as number | undefined) ?? 1;
  return {
    stageId: `stage-${dayNumber}`,
    dayNumber,
    distance: 50,
    elevation: 0,
    elevationLoss: 0,
    startPoint: A,
    endPoint: B,
    geometry: [],
    label: null,
    startLabel: 'Paris',
    endLabel: 'Lyon',
    weather: null,
    alerts: [],
    resupply: EMPTY_RESUPPLY,
    accommodations: [],
    selectedAccommodation: null,
    isRestDay: false,
    ...overrides,
  };
}

const detail = (stages: unknown[]) => ({ title: 'Trip', stages }) as any;
// A cached entry wrapping the same /detail shape (#1147).
const detailCache = (stages: unknown[]) =>
  ({ detail: detail(stages), route: null, syncedAt: 1 }) as any;

function enrichedPayload(): EnrichedStagePayload {
  return {
    stageId: 'stage-1',
    dayNumber: 1,
    distance: 50,
    elevation: 0,
    elevationLoss: 0,
    onCycleNetwork: 0,
    startPoint: A,
    endPoint: B,
    geometry: [],
    label: null,
    weather: null,
    alerts: [],
    resupply: {
      foodAtLunch: [],
      waterMorning: null,
      waterAfternoon: null,
      foodAtArrival: [],
    },
    accommodations: [],
    selectedAccommodation: null,
    events: [],
  };
}

const store = () => useTripStore.getState();

function fakeSub() {
  return { close: jest.fn(), reconnect: jest.fn() };
}
const notCancelled = () => false;

beforeEach(() => {
  jest.clearAllMocks();
  useTripStore.getState().reset();
  useDismissedAlerts.getState().reset();
  useOfflineStore.getState().setOnline(true);
  mockReadCache.mockResolvedValue(null);
});

describe('runTripLive orchestration (#1014)', () => {
  it('hydrates the store then subscribes to SSE (happy path)', async () => {
    mockDetail.mockResolvedValue(detail([apiStage()]));
    const live = fakeSub();
    mockSubscribe.mockReturnValue(live);

    const sub = await runTripLive('t1', store(), notCancelled);

    expect(store().stages).toHaveLength(1);
    expect(store().loading).toBe(false);
    expect(mockSubscribe).toHaveBeenCalledWith(
      't1',
      expect.any(Function),
      expect.objectContaining({ onOpen: expect.any(Function) }),
    );
    expect(sub).toBe(live);
  });

  it('clears alert dismissals from a previous trip on hydrate', async () => {
    // Dismissals are keyed on dayNumber:code (global singleton), so loading a new
    // trip must reset them or a dismissal leaks across trips.
    useDismissedAlerts.getState().dismiss('1:ford_wet');
    expect(useDismissedAlerts.getState().isDismissed('1:ford_wet')).toBe(true);

    mockDetail.mockResolvedValue(detail([apiStage()]));
    mockSubscribe.mockReturnValue(fakeSub());

    await runTripLive('t2', store(), notCancelled);

    expect(useDismissedAlerts.getState().isDismissed('1:ford_wet')).toBe(false);
  });

  it('reconciles a stage_updated SSE event through the core reducers', async () => {
    mockDetail.mockResolvedValue(detail([apiStage({ endLabel: 'Lyon' })]));
    let dispatch: ((event: MercureEvent) => void) | undefined;
    mockSubscribe.mockImplementation((_id, cb) => {
      dispatch = cb;
      return fakeSub();
    });

    await runTripLive('t1', store(), notCancelled);
    expect(dispatch).toBeDefined();

    // The mapped payload carries a null endLabel; with a stable endpoint the core
    // reducer must preserve the previous "Lyon" — proving the full SSE pipeline.
    dispatch!({
      type: 'stage_updated',
      data: { stageId: 'stage-1', position: 0, stage: enrichedPayload() },
    });
    expect(store().stages[0]!.endLabel).toBe('Lyon');
  });

  it('surfaces an error and does not subscribe when /detail fails', async () => {
    mockDetail.mockRejectedValue(new Error('boom'));
    const sub = await runTripLive('t1', store(), notCancelled);
    expect(store().error).toBe('trip.loadError');
    expect(mockSubscribe).not.toHaveBeenCalled();
    expect(sub).toBeUndefined();
  });

  it('reports "Voyage introuvable." when /detail returns null', async () => {
    mockDetail.mockResolvedValue(null);
    await runTripLive('t1', store(), notCancelled);
    expect(store().error).toBe('trip.notFound');
  });

  it('aborts before subscribing when cancelled during the /detail fetch', async () => {
    mockDetail.mockResolvedValue(detail([apiStage()]));
    const sub = await runTripLive('t1', store(), () => true);
    expect(mockSubscribe).not.toHaveBeenCalled();
    expect(sub).toBeUndefined();
  });

  it('caches the /detail payload after a successful online hydrate (#1147)', async () => {
    mockDetail.mockResolvedValue(detail([apiStage()]));
    mockSubscribe.mockReturnValue(fakeSub());

    await runTripLive('t1', store(), notCancelled);

    expect(mockCache).toHaveBeenCalledWith(
      't1',
      expect.objectContaining({ title: 'Trip' }),
    );
  });

  it('hydrates from cache and skips SSE while offline (#1147)', async () => {
    useOfflineStore.getState().setOnline(false);
    mockReadCache.mockResolvedValue(detailCache([apiStage()]));

    const sub = await runTripLive('t1', store(), notCancelled);

    expect(store().stages).toHaveLength(1);
    expect(store().error).toBeNull();
    expect(mockDetail).not.toHaveBeenCalled();
    expect(mockSubscribe).not.toHaveBeenCalled();
    expect(sub).toBeDefined();
  });

  it('falls back to cache when /detail fails, without surfacing an error (#1147)', async () => {
    mockDetail.mockRejectedValue(new Error('offline'));
    mockReadCache.mockResolvedValue(detailCache([apiStage()]));

    const sub = await runTripLive('t1', store(), notCancelled);

    expect(store().stages).toHaveLength(1);
    expect(store().error).toBeNull();
    expect(mockSubscribe).not.toHaveBeenCalled();
    expect(sub).toBeDefined();
  });
});

describe('computing state machine driven by SSE', () => {
  async function connect(): Promise<(event: MercureEvent) => void> {
    mockDetail.mockResolvedValue(detail([apiStage()]));
    let dispatch: ((event: MercureEvent) => void) | undefined;
    mockSubscribe.mockImplementation((_id, cb) => {
      dispatch = cb;
      return fakeSub();
    });
    await runTripLive('t1', store(), notCancelled);
    expect(dispatch).toBeDefined();
    return dispatch!;
  }

  const stepCompleted: MercureEvent = {
    type: 'computation_step_completed',
    data: { step: 'route', category: 'route', completed: 1, total: 5 },
  };

  it('sets computing=true on computation_step_completed', async () => {
    const dispatch = await connect();
    expect(store().computing).toBe(false);
    dispatch(stepCompleted);
    expect(store().computing).toBe(true);
  });

  it('clears computing on trip_ready', async () => {
    const dispatch = await connect();
    dispatch(stepCompleted);
    dispatch({
      type: 'trip_ready',
      data: { stages: [enrichedPayload()], computationStatus: {} },
    });
    expect(store().computing).toBe(false);
  });

  it('clears computing on trip_complete', async () => {
    const dispatch = await connect();
    dispatch(stepCompleted);
    dispatch({ type: 'trip_complete', data: { computationStatus: {} } });
    expect(store().computing).toBe(false);
  });

  it('clears computing on a non-retryable computation_error', async () => {
    const dispatch = await connect();
    dispatch(stepCompleted);
    dispatch({
      type: 'computation_error',
      data: { computation: 'weather', message: 'boom', retryable: false },
    });
    expect(store().computing).toBe(false);
  });

  it('keeps computing=true on a retryable computation_error', async () => {
    const dispatch = await connect();
    dispatch(stepCompleted);
    dispatch({
      type: 'computation_error',
      data: { computation: 'weather', message: 'transient', retryable: true },
    });
    expect(store().computing).toBe(true);
  });

  it('keeps the armed baseline on a non-retryable computation_error', async () => {
    // The backend completion gate guarantees a trip_ready still follows once
    // every pipeline computation has settled (done OR failed), so a single
    // non-critical failure must NOT disarm the baseline — otherwise the highlight
    // is dropped for the common partial-failure case.
    const dispatch = await connect();
    useTripStore.getState().armConfigDiff();
    expect(store().diffBaseline).not.toBeNull();

    dispatch({
      type: 'computation_error',
      data: { computation: 'route', message: 'fatal', retryable: false },
    });
    expect(store().diffBaseline).not.toBeNull();
  });

  it('leaves the armed baseline intact on a retryable computation_error', async () => {
    const dispatch = await connect();
    useTripStore.getState().armConfigDiff();

    dispatch({
      type: 'computation_error',
      data: { computation: 'route', message: 'transient', retryable: true },
    });
    // Still running → the recompute may yet produce a trip_ready that diffs.
    expect(store().diffBaseline).not.toBeNull();
  });

  it('applies route_segment_recalculated to the stage live (#1179)', async () => {
    // Adding a POI waypoint re-routes one segment via route_segment_recalculated
    // (no terminal trip_ready). Mobile used to ignore it; it now flows through the
    // shared reducer so the stage's distance/geometry update in place.
    const dispatch = await connect();
    expect(store().stages[0].distance).toBe(50);

    dispatch({
      type: 'route_segment_recalculated',
      data: {
        stageId: 'stage-1',
        reason: 'waypoint_added',
        distance: 42000,
        elevationGain: 99,
        duration: 3600,
        coordinates: [A, B, A],
      },
    });

    expect(store().stages[0].distance).toBe(42);
    expect(store().stages[0].geometry).toHaveLength(3);
  });

  it('applies a weather enrichment event to the stage live', async () => {
    const dispatch = await connect();
    expect(store().stages[0].weather).toBeNull();

    dispatch({
      type: 'weather_fetched',
      data: {
        stages: [
          {
            dayNumber: 1,
            weather: {
              icon: 'sun',
              description: 'Clear',
              tempMin: 8,
              tempMax: 17,
              windSpeed: 12,
              windDirection: 'S',
              precipitationProbability: 20,
              humidity: 60,
              comfortIndex: 4,
              relativeWindDirection: 'tailwind',
              apparentTempMin: 6,
              apparentTempMax: 15,
              windGusts: 20,
              precipitationMm: 0.8,
              uvIndex: 2,
              hourly: [],
            },
          },
        ],
      },
    });

    expect(store().stages[0].weather).not.toBeNull();
  });
});

// Minimal renderHook on react-test-renderer (the mobile convention, no RTL).
async function renderUseTripLive(
  id: string,
  options?: { enabled?: boolean },
): Promise<{ unmount: () => void }> {
  function Probe() {
    useTripLive(id, options);
    return null;
  }
  let renderer!: ReturnType<typeof TestRenderer.create>;
  await act(async () => {
    renderer = TestRenderer.create(createElement(Probe));
  });
  return { unmount: () => act(() => renderer.unmount()) };
}

describe('useTripLive enabled gate (#1039)', () => {
  it('runs no orchestration and never resets on unmount when enabled=false', async () => {
    // The tap-through case: the roadbook already owns the live store for this
    // trip; the detail screen must not re-hydrate nor reset() on back-nav.
    useTripStore.setState({ tripId: 't1', stages: [apiStage()] as never });

    const { unmount } = await renderUseTripLive('t1', { enabled: false });
    expect(mockDetail).not.toHaveBeenCalled();

    unmount();
    // reset() would null tripId and empty stages — assert it didn't fire.
    expect(store().tripId).toBe('t1');
    expect(store().stages).toHaveLength(1);
  });

  it('runs the orchestration and resets on unmount when enabled (default)', async () => {
    mockDetail.mockResolvedValue(detail([apiStage()]));
    mockSubscribe.mockReturnValue(fakeSub());

    const { unmount } = await renderUseTripLive('t1');
    expect(mockDetail).toHaveBeenCalledWith('t1');
    expect(store().tripId).toBe('t1');

    unmount();
    expect(store().tripId).toBeNull();
    expect(store().stages).toHaveLength(0);
  });
});

describe('useTripLive keeps the roadbook live across gaps', () => {
  let appStateListener: (state: string) => void;
  const removeAppState = jest.fn();
  let live: ReturnType<typeof fakeSub>;
  let onOpen: SubscribeOptions['onOpen'];
  let appStateSpy: jest.SpyInstance;

  beforeEach(() => {
    appStateListener = () => {};
    appStateSpy = jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation((_event, cb) => {
        appStateListener = cb as (state: string) => void;
        return { remove: removeAppState } as never;
      });
    live = fakeSub();
    mockSubscribe.mockImplementation((_id, _cb, options) => {
      onOpen = options?.onOpen;
      return live;
    });
    mockDetail.mockResolvedValue(detail([apiStage()]));
  });

  afterEach(() => appStateSpy.mockRestore());

  it('reopens the stream when the app comes back to the foreground', async () => {
    const { unmount } = await renderUseTripLive('t1');

    act(() => appStateListener('background'));
    expect(live.reconnect).not.toHaveBeenCalled();
    act(() => appStateListener('active'));
    expect(live.reconnect).toHaveBeenCalledTimes(1);

    unmount();
  });

  it('does not reopen on foreground while the device is offline', async () => {
    const { unmount } = await renderUseTripLive('t1');
    act(() => useOfflineStore.getState().setOnline(false));

    act(() => appStateListener('active'));
    expect(live.reconnect).not.toHaveBeenCalled();

    unmount();
  });

  it('reopens the stream when NetInfo reports the network back', async () => {
    function Probe() {
      useConnectivity();
      useTripLive('t1');
      return null;
    }
    let renderer!: ReturnType<typeof TestRenderer.create>;
    await act(async () => {
      renderer = TestRenderer.create(createElement(Probe));
    });

    act(() => mockNetInfoListener!({ isConnected: false, isInternetReachable: false }));
    expect(live.reconnect).not.toHaveBeenCalled();
    act(() => mockNetInfoListener!({ isConnected: true, isInternetReachable: true }));
    expect(live.reconnect).toHaveBeenCalledTimes(1);
    // Staying online is not a regain.
    act(() => mockNetInfoListener!({ isConnected: true, isInternetReachable: true }));
    expect(live.reconnect).toHaveBeenCalledTimes(1);

    act(() => renderer.unmount());
  });

  it('re-reads /detail when the stream reopens, not on the first open', async () => {
    const { unmount } = await renderUseTripLive('t1');
    expect(mockDetail).toHaveBeenCalledTimes(1);

    await act(async () => onOpen!(false));
    expect(mockDetail).toHaveBeenCalledTimes(1);

    mockDetail.mockResolvedValue(detail([apiStage({ isRestDay: true })]));
    await act(async () => onOpen!(true));
    expect(mockDetail).toHaveBeenCalledTimes(2);
    expect(store().stages[0]!.isRestDay).toBe(true);
    expect(mockCache).toHaveBeenCalledTimes(2);

    unmount();
  });

  it('removes every listener and closes the stream on unmount', async () => {
    const { unmount } = await renderUseTripLive('t1');
    unmount();

    expect(removeAppState).toHaveBeenCalled();
    expect(live.close).toHaveBeenCalledTimes(1);
    // The store subscription is gone too: a network regain after unmount is a no-op.
    act(() => useOfflineStore.getState().setOnline(false));
    act(() => useOfflineStore.getState().setOnline(true));
    expect(live.reconnect).not.toHaveBeenCalled();
  });

  it('drops a resync that lands after unmount', async () => {
    const { unmount } = await renderUseTripLive('t1');
    let release!: (value: unknown) => void;
    mockDetail.mockReturnValue(new Promise((resolve) => (release = resolve)) as never);
    onOpen!(true);
    unmount();

    await act(async () => release(detail([apiStage(), apiStage({ dayNumber: 2 })])));
    expect(store().stages).toHaveLength(0);
  });

  it('opens a cache-hydrated roadbook on demand and resyncs on its first open', async () => {
    useOfflineStore.getState().setOnline(false);
    mockReadCache.mockResolvedValue(detailCache([apiStage()]));
    const { unmount } = await renderUseTripLive('t1');
    expect(mockSubscribe).not.toHaveBeenCalled();

    act(() => useOfflineStore.getState().setOnline(true));
    expect(mockSubscribe).toHaveBeenCalledTimes(1);

    await act(async () => onOpen!(false));
    expect(mockDetail).toHaveBeenCalledWith('t1');

    unmount();
    expect(live.close).toHaveBeenCalledTimes(1);
  });
});

describe('applyResync', () => {
  beforeEach(() => {
    useTripStore.getState().hydrate('t1', detail([apiStage(), apiStage({ dayNumber: 2 })]));
    useTripStore.getState().applyStageDetail('stage-1', [A, B]);
  });

  it('refreshes the stages but keeps the geometry already loaded', () => {
    useTripStore.setState({ geometryLoaded: true, computing: true });

    applyResync('t1', {
      ...detail([apiStage({ distance: 80 }), apiStage({ dayNumber: 2 })]),
      isLocked: true,
      categoryStatus: { weather: 'done' },
    });

    expect(store().stages[0]!.distance).toBe(80);
    expect(store().stages[0]!.geometry).toEqual([A, B]);
    expect(store().isLocked).toBe(true);
    // A missed trip_ready no longer holds the badge on; the route is fetched again.
    expect(store().computing).toBe(false);
    expect(store().geometryLoaded).toBe(false);
  });

  it('stores the trip dates as calendar days, like hydrate', () => {
    applyResync('t1', {
      ...detail([apiStage()]),
      startDate: '2026-08-01T00:00:00+02:00',
      endDate: '2026-08-03T00:00:00+02:00',
    });

    expect(store().startDate).toBe('2026-08-01');
    expect(store().endDate).toBe('2026-08-03');
  });

  it('keeps the computing badge while a category still runs', () => {
    applyResync('t1', { ...detail([apiStage()]), categoryStatus: { weather: 'running' } });
    expect(store().computing).toBe(true);
  });

  it('leaves queued edits alone', () => {
    useTripStore
      .getState()
      .queueModification({ stageIndex: 0, type: 'distance', label: 'x' });

    applyResync('t1', detail([apiStage({ distance: 80 })]));

    expect(store().stages).toHaveLength(2);
    expect(store().stages[0]!.distance).toBe(50);
  });

  it('ignores a /detail for another trip', () => {
    applyResync('t2', detail([apiStage({ distance: 80 })]));
    expect(store().stages[0]!.distance).toBe(50);
  });
});
