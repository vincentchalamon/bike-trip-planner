/// <reference types="jest" />
import { createElement } from 'react';
import { EMPTY_RESUPPLY } from '@btp/core';
import TestRenderer, { act } from 'react-test-renderer';
import type { EnrichedStagePayload, MercureEvent } from '@btp/core/mercure';
import { runTripLive, useTripLive } from './use-trip-live';
import { useTripStore } from '../store/trip-store';
import { useDismissedAlerts } from '../store/dismissed-alerts';
import { useOfflineStore } from '../store/offline-store';

jest.mock('../api/trips', () => ({ fetchTripDetail: jest.fn() }));
jest.mock('../api/mercure', () => ({
  fetchMercureToken: jest.fn(),
  subscribeToTrip: jest.fn(),
}));
jest.mock('../store/trip-cache', () => ({
  cacheTripDetail: jest.fn(),
  readTripCache: jest.fn(),
}));

import { fetchTripDetail } from '../api/trips';
import { fetchMercureToken, subscribeToTrip } from '../api/mercure';
import { cacheTripDetail, readTripCache } from '../store/trip-cache';

const mockDetail = fetchTripDetail as jest.MockedFunction<typeof fetchTripDetail>;
const mockToken = fetchMercureToken as jest.MockedFunction<typeof fetchMercureToken>;
const mockSubscribe = subscribeToTrip as jest.MockedFunction<typeof subscribeToTrip>;
const mockCache = cacheTripDetail as jest.MockedFunction<typeof cacheTripDetail>;
const mockReadCache = readTripCache as jest.MockedFunction<typeof readTripCache>;

const A = { lat: 1, lon: 1, ele: 0 };
const B = { lat: 2, lon: 2, ele: 0 };

function apiStage(overrides: Record<string, unknown> = {}) {
  return {
    dayNumber: 1,
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

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const detail = (stages: unknown[]) => ({ title: 'Trip', stages }) as any;
// A cached entry wrapping the same /detail shape (#1147).
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const detailCache = (stages: unknown[]) =>
  ({ detail: detail(stages), route: null, syncedAt: 1 }) as any;

function enrichedPayload(): EnrichedStagePayload {
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 0,
    elevationLoss: 0,
    startPoint: A,
    endPoint: B,
    geometry: [],
    label: null,
    weather: null,
    alerts: [],
    resupply: { foodAtLunch: [], waterMorning: null, waterAfternoon: null, foodAtArrival: [] },
    accommodations: [],
    selectedAccommodation: null,
    events: [],
  };
}

const store = () => useTripStore.getState();
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
    mockToken.mockResolvedValue('jwt');
    const close = jest.fn();
    mockSubscribe.mockReturnValue({ close });

    const sub = await runTripLive('t1', store(), notCancelled);

    expect(store().stages).toHaveLength(1);
    expect(store().loading).toBe(false);
    expect(mockSubscribe).toHaveBeenCalledWith('t1', 'jwt', expect.any(Function));
    expect(sub).toEqual({ close });
  });

  it('clears alert dismissals from a previous trip on hydrate', async () => {
    // Dismissals are keyed on dayNumber:code (global singleton), so loading a new
    // trip must reset them or a dismissal leaks across trips.
    useDismissedAlerts.getState().dismiss('1:ford_wet');
    expect(useDismissedAlerts.getState().isDismissed('1:ford_wet')).toBe(true);

    mockDetail.mockResolvedValue(detail([apiStage()]));
    mockToken.mockResolvedValue('jwt');
    mockSubscribe.mockReturnValue({ close: jest.fn() });

    await runTripLive('t2', store(), notCancelled);

    expect(useDismissedAlerts.getState().isDismissed('1:ford_wet')).toBe(false);
  });

  it('reconciles a stage_updated SSE event through the core reducers', async () => {
    mockDetail.mockResolvedValue(detail([apiStage({ endLabel: 'Lyon' })]));
    mockToken.mockResolvedValue('jwt');
    let dispatch: ((event: MercureEvent) => void) | undefined;
    mockSubscribe.mockImplementation((_id, _token, cb) => {
      dispatch = cb;
      return { close: jest.fn() };
    });

    await runTripLive('t1', store(), notCancelled);
    expect(dispatch).toBeDefined();

    // The mapped payload carries a null endLabel; with a stable endpoint the core
    // reducer must preserve the previous "Lyon" — proving the full SSE pipeline.
    dispatch!({
      type: 'stage_updated',
      data: { stageIndex: 0, stage: enrichedPayload() },
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

  it('still renders the hydrated trip when the SSE token fetch fails (swallowed)', async () => {
    mockDetail.mockResolvedValue(detail([apiStage()]));
    mockToken.mockRejectedValue(new Error('no token'));

    const sub = await runTripLive('t1', store(), notCancelled);

    expect(store().stages).toHaveLength(1);
    expect(store().error).toBeNull();
    expect(mockSubscribe).not.toHaveBeenCalled();
    expect(sub).toBeUndefined();
  });

  it('aborts before subscribing when cancelled during the /detail fetch', async () => {
    mockDetail.mockResolvedValue(detail([apiStage()]));
    const sub = await runTripLive('t1', store(), () => true);
    expect(mockSubscribe).not.toHaveBeenCalled();
    expect(sub).toBeUndefined();
  });

  it('caches the /detail payload after a successful online hydrate (#1147)', async () => {
    mockDetail.mockResolvedValue(detail([apiStage()]));
    mockToken.mockResolvedValue('jwt');
    mockSubscribe.mockReturnValue({ close: jest.fn() });

    await runTripLive('t1', store(), notCancelled);

    expect(mockCache).toHaveBeenCalledWith('t1', expect.objectContaining({ title: 'Trip' }));
  });

  it('hydrates from cache and skips SSE while offline (#1147)', async () => {
    useOfflineStore.getState().setOnline(false);
    mockReadCache.mockResolvedValue(detailCache([apiStage()]));

    const sub = await runTripLive('t1', store(), notCancelled);

    expect(store().stages).toHaveLength(1);
    expect(store().error).toBeNull();
    expect(mockDetail).not.toHaveBeenCalled();
    expect(mockSubscribe).not.toHaveBeenCalled();
    expect(sub).toBeUndefined();
  });

  it('falls back to cache when /detail fails, without surfacing an error (#1147)', async () => {
    mockDetail.mockRejectedValue(new Error('offline'));
    mockReadCache.mockResolvedValue(detailCache([apiStage()]));

    const sub = await runTripLive('t1', store(), notCancelled);

    expect(store().stages).toHaveLength(1);
    expect(store().error).toBeNull();
    expect(mockSubscribe).not.toHaveBeenCalled();
    expect(sub).toBeUndefined();
  });
});

describe('computing state machine driven by SSE', () => {
  async function connect(): Promise<(event: MercureEvent) => void> {
    mockDetail.mockResolvedValue(detail([apiStage()]));
    mockToken.mockResolvedValue('jwt');
    let dispatch: ((event: MercureEvent) => void) | undefined;
    mockSubscribe.mockImplementation((_id, _token, cb) => {
      dispatch = cb;
      return { close: jest.fn() };
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
    mockToken.mockResolvedValue('jwt');
    mockSubscribe.mockReturnValue({ close: jest.fn() });

    const { unmount } = await renderUseTripLive('t1');
    expect(mockDetail).toHaveBeenCalledWith('t1');
    expect(store().tripId).toBe('t1');

    unmount();
    expect(store().tripId).toBeNull();
    expect(store().stages).toHaveLength(0);
  });
});
