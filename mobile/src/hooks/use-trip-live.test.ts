/// <reference types="jest" />
import type { EnrichedStagePayload, MercureEvent } from '@btp/core/mercure';
import { runTripLive } from './use-trip-live';
import { useTripStore } from '../store/trip-store';
import { useDismissedAlerts } from '../store/dismissed-alerts';

jest.mock('../api/trips', () => ({ fetchTripDetail: jest.fn() }));
jest.mock('../api/mercure', () => ({
  fetchMercureToken: jest.fn(),
  subscribeToTrip: jest.fn(),
}));

import { fetchTripDetail } from '../api/trips';
import { fetchMercureToken, subscribeToTrip } from '../api/mercure';

const mockDetail = fetchTripDetail as jest.MockedFunction<typeof fetchTripDetail>;
const mockToken = fetchMercureToken as jest.MockedFunction<typeof fetchMercureToken>;
const mockSubscribe = subscribeToTrip as jest.MockedFunction<typeof subscribeToTrip>;

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
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
    isRestDay: false,
    ...overrides,
  };
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const detail = (stages: unknown[]) => ({ title: 'Trip', stages }) as any;

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
    pois: [],
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
    expect(store().error).toBe('Impossible de charger le roadbook.');
    expect(mockSubscribe).not.toHaveBeenCalled();
    expect(sub).toBeUndefined();
  });

  it('reports "Voyage introuvable." when /detail returns null', async () => {
    mockDetail.mockResolvedValue(null);
    await runTripLive('t1', store(), notCancelled);
    expect(store().error).toBe('Voyage introuvable.');
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
});
