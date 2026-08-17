/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { Alert } from 'react-native';
import {
  confirmDeleteTrip,
  hasActiveFilter,
  hasMorePages,
  runDeleteTrip,
  runLoadTrips,
  useTrips,
  type UseTrips,
} from './use-trips';

jest.mock('../api/trips', () => ({
  fetchTrips: jest.fn(),
  deleteTrip: jest.fn(),
}));
import { deleteTrip, fetchTrips } from '../api/trips';
const mockFetch = fetchTrips as jest.MockedFunction<typeof fetchTrips>;
const mockDelete = deleteTrip as jest.MockedFunction<typeof deleteTrip>;

beforeEach(() => jest.clearAllMocks());

describe('runLoadTrips (#1036)', () => {
  it('returns the page + total on success', async () => {
    mockFetch.mockResolvedValue({ items: [{ id: 't1' }], totalItems: 30 } as never);
    const res = await runLoadTrips(1, { title: 'foo' });
    expect(mockFetch).toHaveBeenCalledWith(1, { title: 'foo' });
    expect(res.items).toHaveLength(1);
    expect(res.totalItems).toBe(30);
    expect(res.error).toBeNull();
  });

  it('returns an empty page + a message when the fetch throws', async () => {
    mockFetch.mockRejectedValue(new Error('boom'));
    const res = await runLoadTrips(2, {});
    expect(res.items).toEqual([]);
    expect(res.totalItems).toBe(0);
    expect(res.error).toBe('Impossible de charger les voyages.');
  });
});

describe('runDeleteTrip (#1036)', () => {
  it('resolves null on a successful delete', async () => {
    mockDelete.mockResolvedValue({ ok: true, status: 204 });
    expect(await runDeleteTrip('t1')).toBeNull();
  });

  it('resolves a message when the delete fails (incl. 404 non-owner masking)', async () => {
    mockDelete.mockResolvedValue({ ok: false, status: 404 });
    expect(await runDeleteTrip('t1')).toBe('La suppression a échoué.');
  });
});

describe('pagination + filter predicates (#1036)', () => {
  it('hasMorePages is true while fewer items are loaded than the total', () => {
    expect(hasMorePages(12, 30)).toBe(true);
    expect(hasMorePages(30, 30)).toBe(false);
    expect(hasMorePages(0, 0)).toBe(false);
  });

  it('hasActiveFilter distinguishes "no results" from "no trips"', () => {
    expect(hasActiveFilter({ title: '', startDate: '', endDate: '' })).toBe(false);
    expect(hasActiveFilter({ title: 'alps' })).toBe(true);
    expect(hasActiveFilter({ startDate: '2026-01-01' })).toBe(true);
    expect(hasActiveFilter({ endDate: '2026-02-01' })).toBe(true);
  });
});

function deferred<T>(): { promise: Promise<T>; resolve: (v: T) => void } {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

function page(items: string[], totalItems: number) {
  return { items: items.map((id) => ({ id })), totalItems } as never;
}

// Minimal renderHook on react-test-renderer (the mobile convention, no RTL).
function renderHook(): { result: { current: UseTrips }; unmount: () => void } {
  const result = { current: undefined as unknown as UseTrips };
  function Probe() {
    result.current = useTrips();
    return null;
  }
  let renderer!: ReturnType<typeof TestRenderer.create>;
  act(() => {
    renderer = TestRenderer.create(createElement(Probe));
  });
  return { result, unmount: () => act(() => renderer.unmount()) };
}

describe('useTrips filter/pagination races (#1036)', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('drops a loadMore response that lands after the filter changed', async () => {
    const initial = deferred<never>();
    const stalePage2 = deferred<never>();
    const refetched = deferred<never>();
    mockFetch
      .mockReturnValueOnce(initial.promise) // mount: page 1, empty filters
      .mockReturnValueOnce(stalePage2.promise) // loadMore: page 2, empty filters
      .mockReturnValueOnce(refetched.promise); // page 1, new title filter

    const { result, unmount } = renderHook();
    await act(async () => {
      initial.resolve(page(['a', 'b'], 30));
    });
    expect(result.current.trips.map((t) => t.id)).toEqual(['a', 'b']);

    // loadMore is now in flight (page 2 of the empty-filter query)...
    act(() => result.current.loadMore());
    expect(mockFetch).toHaveBeenLastCalledWith(2, {
      title: '',
      startDate: '',
      endDate: '',
    });

    // ...the user changes the title before it lands: page 1 refetches.
    act(() => result.current.setTitle('paris'));
    await act(async () => {
      jest.advanceTimersByTime(300);
    });
    await act(async () => {
      refetched.resolve(page(['x'], 5));
    });
    expect(result.current.trips.map((t) => t.id)).toEqual(['x']);

    // The stale page-2 response lands last and must be ignored, not appended.
    await act(async () => {
      stalePage2.resolve(page(['c', 'd'], 30));
    });
    expect(result.current.trips.map((t) => t.id)).toEqual(['x']);
    expect(result.current.loadingMore).toBe(false);
    unmount();
  });

  it('routes date changes through the same debounced load path as the title', async () => {
    const initial = deferred<never>();
    mockFetch.mockReturnValueOnce(initial.promise);
    const { result, unmount } = renderHook();
    await act(async () => {
      initial.resolve(page([], 0));
    });
    mockFetch.mockClear();

    // A date change must not refetch synchronously (debounced, like the title)...
    const afterDate = deferred<never>();
    mockFetch.mockReturnValueOnce(afterDate.promise);
    act(() => result.current.setStartDate('2026-01-01'));
    expect(mockFetch).not.toHaveBeenCalled();

    // ...it fires once, after the debounce, as a single unified filter object.
    await act(async () => {
      jest.advanceTimersByTime(300);
    });
    await act(async () => {
      afterDate.resolve(page([], 0));
    });
    expect(mockFetch).toHaveBeenCalledTimes(1);
    expect(mockFetch).toHaveBeenCalledWith(1, {
      title: '',
      startDate: '2026-01-01',
      endDate: '',
    });
    unmount();
  });
});

describe('confirmDeleteTrip (#1036)', () => {
  it('opens a confirm dialog and runs onConfirm only on the destructive button', () => {
    const spy = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    const onConfirm = jest.fn();
    confirmDeleteTrip({
      title: 'Delete?',
      message: 'Gone forever',
      cancel: 'Cancel',
      confirm: 'Delete',
      onConfirm,
    });

    expect(spy).toHaveBeenCalledTimes(1);
    const [title, , buttons] = spy.mock.calls[0] as [
      string,
      string,
      { text: string; style?: string; onPress?: () => void }[],
    ];
    expect(title).toBe('Delete?');
    const cancel = buttons.find((b) => b.style === 'cancel');
    const destructive = buttons.find((b) => b.style === 'destructive');
    expect(cancel).toBeDefined();
    expect(destructive).toBeDefined();
    expect(onConfirm).not.toHaveBeenCalled();
    destructive!.onPress?.();
    expect(onConfirm).toHaveBeenCalledTimes(1);
    spy.mockRestore();
  });
});
