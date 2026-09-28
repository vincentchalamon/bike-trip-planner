/// <reference types="jest" />

type Listener = (event: Record<string, unknown>) => void;

// A react-native-sse stand-in that records every instance, so a test can tell
// which connection is live, which were closed and which token each one carried.
class MockEventSource {
  static instances: MockEventSource[] = [];
  listeners: Record<string, Listener[]> = {};
  closed = false;
  constructor(
    public url: string,
    public options: { headers: Record<string, string> },
  ) {
    MockEventSource.instances.push(this);
  }
  addEventListener(type: string, fn: Listener): void {
    (this.listeners[type] ??= []).push(fn);
  }
  removeAllEventListeners(): void {
    this.listeners = {};
  }
  close(): void {
    this.closed = true;
  }
  emit(type: string, event: Record<string, unknown> = { type }): void {
    (this.listeners[type] ?? []).forEach((fn) => fn(event));
  }
  get token(): string {
    return this.options.headers.Authorization!.replace('Bearer ', '');
  }
}

// Lazy getter: the factory runs at import time, before the class above is initialised.
jest.mock('react-native-sse', () => ({
  __esModule: true,
  get default() {
    return MockEventSource;
  },
}));
jest.mock('./trips', () => ({ setTripVersion: jest.fn() }));
jest.mock('./client', () => ({ api: { GET: jest.fn() } }));
jest.mock('./config', () => ({ API_BASE_URL: 'https://localhost' }));

import { decodeBase64Url, renewDelayMs, subscribeToTrip } from './mercure';
import { setTripVersion } from './trips';
import { api } from './client';

const mockGet = api.GET as jest.Mock;

// Base64url without padding, as a real JWT encodes its segments.
function jwt(claims: Record<string, unknown>): string {
  const payload = btoa(JSON.stringify(claims))
    .replace(/=+$/, '')
    .replace(/\+/g, '-')
    .replace(/\//g, '_');
  return `h.${payload}.s`;
}

// Hands out tokens t1, t2, ... valid one hour from "now" (the fake clock).
function serveTokens(): void {
  let n = 0;
  mockGet.mockImplementation(async () => {
    n += 1;
    const iat = Math.floor(Date.now() / 1000);
    return { data: { token: jwt({ n, iat, exp: iat + 3600 }) } };
  });
}

const tokenNumber = (source: MockEventSource) => {
  const payload = source.token.split('.')[1]!.replace(/-/g, '+').replace(/_/g, '/');
  return JSON.parse(atob(payload.padEnd(Math.ceil(payload.length / 4) * 4, '='))).n;
};

const live = () => MockEventSource.instances.filter((es) => !es.closed);
const last = () => MockEventSource.instances[MockEventSource.instances.length - 1]!;

// Let the token fetch promise settle.
const flush = () => jest.advanceTimersByTimeAsync(0);

beforeEach(() => {
  jest.useFakeTimers();
  jest.clearAllMocks();
  MockEventSource.instances = [];
  serveTokens();
});

afterEach(() => {
  jest.useRealTimers();
});

describe('subscribeToTrip message handling', () => {
  async function connected(onEvent: (e: unknown) => void) {
    subscribeToTrip('t1', onEvent);
    await flush();
    return last();
  }

  it('ignores a frame that is not JSON', async () => {
    const onEvent = jest.fn();
    const es = await connected(onEvent);

    expect(() => es.emit('message', { type: 'message', data: 'not json' })).not.toThrow();
    expect(() => es.emit('message', { type: 'message', data: 'null' })).not.toThrow();
    expect(onEvent).not.toHaveBeenCalled();
  });

  it('records the version and forwards a parsed envelope', async () => {
    const onEvent = jest.fn();
    const es = await connected(onEvent);

    es.emit('message', {
      type: 'message',
      data: JSON.stringify({ type: 'trip_ready', version: 4, data: {} }),
    });

    expect(setTripVersion).toHaveBeenCalledWith('t1', 4);
    expect(onEvent).toHaveBeenCalledTimes(1);
  });

  // A reducer or store bug must surface, not be swallowed as a keep-alive frame.
  it('lets an exception thrown while handling the event propagate', async () => {
    const es = await connected(() => {
      throw new Error('reducer bug');
    });

    expect(() =>
      es.emit('message', {
        type: 'message',
        data: JSON.stringify({ type: 'trip_ready', data: {} }),
      }),
    ).toThrow('reducer bug');
  });
});

describe('renewDelayMs', () => {
  it('renews a minute before the TTL runs out, counted from iat', () => {
    // A device clock two hours ahead must not matter.
    jest.setSystemTime(new Date('2026-09-28T12:00:00Z'));
    expect(renewDelayMs(jwt({ iat: 1000, exp: 1000 + 3600 }))).toBe(3540_000);
  });

  it('falls back to the device clock without iat', () => {
    jest.setSystemTime(new Date('2026-09-28T12:00:00Z'));
    const now = Date.now() / 1000;
    expect(renewDelayMs(jwt({ exp: now + 600 }))).toBe(540_000);
  });

  it('never returns a delay short enough to loop', () => {
    expect(renewDelayMs(jwt({ iat: 1000, exp: 1010 }))).toBe(30_000);
  });

  // Jest runs on Node, which has `atob`; the device engine may not.
  it('does not depend on a global atob', () => {
    const original = globalThis.atob;
    // @ts-expect-error simulating an engine without atob
    delete globalThis.atob;
    try {
      expect(renewDelayMs(jwt({ iat: 1000, exp: 1000 + 3600 }))).toBe(3540_000);
    } finally {
      globalThis.atob = original;
    }
  });

  it('decodes base64url with and without padding', () => {
    expect(decodeBase64Url('eyJhIjoiPz8_In0')).toBe('{"a":"???"}');
    expect(decodeBase64Url('YQ==')).toBe('a');
    expect(decodeBase64Url('YWI')).toBe('ab');
    expect(() => decodeBase64Url('a%b')).toThrow();
  });

  it('returns null when the token says nothing about its expiry', () => {
    expect(renewDelayMs(jwt({ iat: 1000 }))).toBeNull();
    expect(renewDelayMs('not-a-jwt')).toBeNull();
    expect(renewDelayMs('h.%%%.s')).toBeNull();
  });
});

describe('subscribeToTrip connection lifecycle', () => {
  it('opens with a freshly fetched token and reports the first open', async () => {
    const onOpen = jest.fn();
    subscribeToTrip('t1', jest.fn(), { onOpen });
    await flush();

    expect(live()).toHaveLength(1);
    expect(last().url).toBe('https://localhost/.well-known/mercure?match=%2Ftrips%2Ft1');
    expect(tokenNumber(last())).toBe(1);

    last().emit('open');
    expect(onOpen).toHaveBeenCalledWith(false);
  });

  it('renews the token before it expires and reopens with it', async () => {
    const onOpen = jest.fn();
    subscribeToTrip('t1', jest.fn(), { onOpen });
    await flush();
    const first = last();
    first.emit('open');

    await jest.advanceTimersByTimeAsync(3540_000 - 1);
    expect(mockGet).toHaveBeenCalledTimes(1);

    await jest.advanceTimersByTimeAsync(1);
    expect(mockGet).toHaveBeenCalledTimes(2);
    expect(first.closed).toBe(true);
    expect(live()).toHaveLength(1);
    expect(tokenNumber(last())).toBe(2);

    last().emit('open');
    expect(onOpen).toHaveBeenLastCalledWith(true);
  });

  it('answers a 401 with a new token on a new connection', async () => {
    const onOpen = jest.fn();
    subscribeToTrip('t1', jest.fn(), { onOpen });
    await flush();
    const first = last();
    first.emit('open');

    first.emit('error', { type: 'error', xhrStatus: 401, xhrState: 4 });
    // The library would keep polling with the dead header: the source is closed at once.
    expect(first.closed).toBe(true);
    expect(live()).toHaveLength(0);

    await jest.advanceTimersByTimeAsync(1_000);
    expect(live()).toHaveLength(1);
    expect(tokenNumber(last())).toBe(2);

    last().emit('open');
    expect(onOpen).toHaveBeenLastCalledWith(true);
  });

  it('backs off exponentially while the token cannot be fetched, then resets', async () => {
    mockGet.mockRejectedValue(new Error('offline'));
    subscribeToTrip('t1', jest.fn());
    await flush();
    expect(mockGet).toHaveBeenCalledTimes(1);

    await jest.advanceTimersByTimeAsync(1_000);
    expect(mockGet).toHaveBeenCalledTimes(2);
    await jest.advanceTimersByTimeAsync(1_999);
    expect(mockGet).toHaveBeenCalledTimes(2);
    await jest.advanceTimersByTimeAsync(1);
    expect(mockGet).toHaveBeenCalledTimes(3);

    serveTokens();
    await jest.advanceTimersByTimeAsync(4_000);
    expect(live()).toHaveLength(1);
    last().emit('open');

    // A later failure starts again from the initial delay.
    last().emit('error');
    await jest.advanceTimersByTimeAsync(1_000);
    expect(live()).toHaveLength(1);
  });

  it('reconnect() swaps the connection at once and drops a stale in-flight fetch', async () => {
    const sub = subscribeToTrip('t1', jest.fn());
    await flush();
    const first = last();

    sub.reconnect();
    sub.reconnect();
    await flush();

    expect(first.closed).toBe(true);
    expect(live()).toHaveLength(1);
    // Both fetches ran but only the newest opened a connection.
    expect(MockEventSource.instances).toHaveLength(2);
    expect(tokenNumber(last())).toBe(3);
  });

  it('close() leaves no connection and no timer behind, even with a fetch in flight', async () => {
    const sub = subscribeToTrip('t1', jest.fn());
    await flush();
    last().emit('error');
    expect(jest.getTimerCount()).toBe(1);

    let release!: (value: unknown) => void;
    mockGet.mockReturnValue(new Promise((resolve) => (release = resolve)));
    await jest.advanceTimersByTimeAsync(1_000);
    expect(mockGet).toHaveBeenCalledTimes(2);

    sub.close();
    sub.reconnect();
    release({ data: { token: jwt({ n: 9, iat: 0, exp: 3600 }) } });
    await flush();

    expect(live()).toHaveLength(0);
    expect(MockEventSource.instances).toHaveLength(1);
    expect(jest.getTimerCount()).toBe(0);
  });
});
