/// <reference types="jest" />

const listeners: Record<string, (event: { type: string; data: string | null }) => void> = {};

jest.mock('react-native-sse', () => ({
  __esModule: true,
  default: jest.fn().mockImplementation(() => ({
    addEventListener: (type: string, fn: (event: { type: string; data: string | null }) => void) => {
      listeners[type] = fn;
    },
    removeAllEventListeners: jest.fn(),
    close: jest.fn(),
  })),
}));
jest.mock('./trips', () => ({ setTripVersion: jest.fn() }));
jest.mock('./client', () => ({ api: { GET: jest.fn() } }));
jest.mock('./config', () => ({ API_BASE_URL: 'https://localhost' }));

import { subscribeToTrip } from './mercure';
import { setTripVersion } from './trips';

function emit(data: string | null): void {
  listeners.message!({ type: 'message', data });
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('subscribeToTrip', () => {
  it('ignores a frame that is not JSON', () => {
    const onEvent = jest.fn();
    subscribeToTrip('t1', 'jwt', onEvent);

    expect(() => emit('not json')).not.toThrow();
    expect(() => emit('null')).not.toThrow();
    expect(onEvent).not.toHaveBeenCalled();
  });

  it('records the version and forwards a parsed envelope', () => {
    const onEvent = jest.fn();
    subscribeToTrip('t1', 'jwt', onEvent);

    emit(JSON.stringify({ type: 'trip_ready', version: 4, data: {} }));

    expect(setTripVersion).toHaveBeenCalledWith('t1', 4);
    expect(onEvent).toHaveBeenCalledTimes(1);
  });

  // A reducer or store bug must surface, not be swallowed as a keep-alive frame.
  it('lets an exception thrown while handling the event propagate', () => {
    subscribeToTrip('t1', 'jwt', () => {
      throw new Error('reducer bug');
    });

    expect(() => emit(JSON.stringify({ type: 'trip_ready', data: {} }))).toThrow('reducer bug');
  });
});
