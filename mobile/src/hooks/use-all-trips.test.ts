/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { createElement } from 'react';
import type { TripListItem } from '../api/trips';

jest.mock('../api/trips', () => ({ fetchAllTrips: jest.fn() }));
import { fetchAllTrips } from '../api/trips';
import { useAllTrips } from './use-all-trips';

const mockFetch = fetchAllTrips as jest.MockedFunction<typeof fetchAllTrips>;

const trip = (id: string): TripListItem => ({ id }) as TripListItem;

function deferred<T>(): { promise: Promise<T>; resolve: (v: T) => void } {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

async function flush(): Promise<void> {
  await act(async () => {
    for (let i = 0; i < 8; i += 1) await Promise.resolve();
  });
}

function renderProbe(key: number | null) {
  let value: TripListItem[] = [];
  function Probe({ k }: { k: number | null }): null {
    value = useAllTrips(k);
    return null;
  }
  let renderer!: ReturnType<typeof TestRenderer.create>;
  act(() => {
    renderer = TestRenderer.create(createElement(Probe, { k: key }));
  });
  return {
    get value(): TripListItem[] {
      return value;
    },
    rerender: (k: number | null) =>
      act(() => renderer.update(createElement(Probe, { k }))),
  };
}

beforeEach(() => jest.clearAllMocks());

describe('useAllTrips', () => {
  it('does not fetch while paused (null key)', async () => {
    const probe = renderProbe(null);
    await flush();
    expect(mockFetch).not.toHaveBeenCalled();
    expect(probe.value).toEqual([]);
  });

  it('returns every trip once the fetch resolves', async () => {
    mockFetch.mockResolvedValue([trip('a'), trip('b')]);
    const probe = renderProbe(1);
    await flush();
    expect(probe.value).toEqual([trip('a'), trip('b')]);
  });

  it('drops a stale in-flight response so it cannot clobber the newer list', async () => {
    const first = deferred<TripListItem[]>();
    const second = deferred<TripListItem[]>();
    mockFetch
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise);

    const probe = renderProbe(1); // starts the first fetch
    probe.rerender(2); // key changed: the first effect is cleaned up (cancelled)

    // The newer fetch resolves first and wins.
    second.resolve([trip('new')]);
    await flush();
    expect(probe.value).toEqual([trip('new')]);

    // The stale first fetch resolves late; its cancelled guard must ignore it.
    first.resolve([trip('stale')]);
    await flush();
    expect(probe.value).toEqual([trip('new')]);
  });

  it('keeps the last list when a fetch rejects (best-effort)', async () => {
    mockFetch.mockResolvedValueOnce([trip('a')]);
    const probe = renderProbe(1);
    await flush();
    expect(probe.value).toEqual([trip('a')]);

    mockFetch.mockRejectedValueOnce(new Error('offline'));
    probe.rerender(2);
    await flush();
    expect(probe.value).toEqual([trip('a')]);
  });
});
