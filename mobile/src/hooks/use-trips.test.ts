/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { Alert } from 'react-native';
import {
  confirmDeleteTrip,
  hasActiveFilter,
  hasMorePages,
  runDeleteTrip,
  runDuplicateTrip,
  runLoadTrips,
  useTrips,
  type UseTrips,
} from './use-trips';
import { useOfflineStore } from '../store/offline-store';

jest.mock('../api/trips', () => ({
  fetchTrips: jest.fn(),
  deleteTrip: jest.fn(),
  duplicateTrip: jest.fn(),
}));
jest.mock('../notifications/native', () => ({
  cancelLocalNotification: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('../store/trips-list-cache', () => ({
  cacheTripList: jest.fn(),
  readCachedTripList: jest.fn().mockResolvedValue(null),
  clearCachedTripList: jest.fn(),
}));
const mockClearDelivered = jest.fn();
jest.mock('../store/delivered-notifications', () => ({
  useDeliveredNotifications: { getState: () => ({ clearDelivered: mockClearDelivered }) },
}));
import { deleteTrip, duplicateTrip, fetchTrips } from '../api/trips';
import { cancelLocalNotification } from '../notifications/native';
import { cacheTripList, readCachedTripList } from '../store/trips-list-cache';
const mockCacheList = cacheTripList as jest.MockedFunction<typeof cacheTripList>;
const mockReadCachedList = readCachedTripList as jest.MockedFunction<typeof readCachedTripList>;
import { notificationIdentifier } from '../notifications/plan';
const mockFetch = fetchTrips as jest.MockedFunction<typeof fetchTrips>;
const mockDelete = deleteTrip as jest.MockedFunction<typeof deleteTrip>;
const mockDuplicate = duplicateTrip as jest.MockedFunction<typeof duplicateTrip>;
const mockCancel = cancelLocalNotification as jest.MockedFunction<
  typeof cancelLocalNotification
>;

beforeEach(() => {
  jest.clearAllMocks();
  useOfflineStore.setState({ isOnline: true });
});

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

  it('caches the first unfiltered page on success (#1167)', async () => {
    mockFetch.mockResolvedValue({ items: [{ id: 't1' }], totalItems: 3 } as never);
    await runLoadTrips(1, {});
    expect(mockCacheList).toHaveBeenCalledWith([{ id: 't1' }]);
  });

  it('does not cache a filtered page or a later page (#1167)', async () => {
    mockFetch.mockResolvedValue({ items: [{ id: 't1' }], totalItems: 3 } as never);
    await runLoadTrips(1, { title: 'foo' });
    await runLoadTrips(2, {});
    expect(mockCacheList).not.toHaveBeenCalled();
  });

  it('falls back to the cached list when the fetch throws, page 1 unfiltered (#1167)', async () => {
    mockFetch.mockRejectedValue(new Error('offline'));
    mockReadCachedList.mockResolvedValue([{ id: 'c1' }, { id: 'c2' }] as never);
    const res = await runLoadTrips(1, {});
    expect(res.items).toEqual([{ id: 'c1' }, { id: 'c2' }]);
    expect(res.totalItems).toBe(2);
    expect(res.error).toBeNull();
  });

  it('surfaces the error when the fetch throws and the cache is empty (#1167)', async () => {
    mockFetch.mockRejectedValue(new Error('offline'));
    mockReadCachedList.mockResolvedValue(null);
    const res = await runLoadTrips(1, {});
    expect(res.items).toEqual([]);
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

  it('cancels the deleted trip local reminders (delete site, not list diff) (#1121)', async () => {
    mockDelete.mockResolvedValue({ ok: true, status: 204 });
    await runDeleteTrip('t1');
    expect(mockCancel).toHaveBeenCalledWith(notificationIdentifier('offlineNotReady', 't1'));
    expect(mockCancel).toHaveBeenCalledWith(notificationIdentifier('tripNoDate', 't1'));
  });

  it('forgets the deleted trip delivered marks so the persisted set does not leak (#1144)', async () => {
    mockDelete.mockResolvedValue({ ok: true, status: 204 });
    await runDeleteTrip('t1');
    expect(mockClearDelivered).toHaveBeenCalledWith(notificationIdentifier('offlineNotReady', 't1'));
    expect(mockClearDelivered).toHaveBeenCalledWith(notificationIdentifier('tripNoDate', 't1'));
  });

  it('does not cancel reminders when the delete fails (#1121)', async () => {
    mockDelete.mockResolvedValue({ ok: false, status: 404 });
    await runDeleteTrip('t1');
    expect(mockCancel).not.toHaveBeenCalled();
  });

  it('never fails the delete when a reminder cancel rejects (best-effort) (#1121)', async () => {
    mockDelete.mockResolvedValue({ ok: true, status: 204 });
    mockCancel.mockRejectedValueOnce(new Error('boom'));
    expect(await runDeleteTrip('t1')).toBeNull();
  });
});

describe('runDuplicateTrip (#1043)', () => {
  it('returns the new trip id on success', async () => {
    mockDuplicate.mockResolvedValue('t2');
    expect(await runDuplicateTrip('t1')).toBe('t2');
    expect(mockDuplicate).toHaveBeenCalledWith('t1');
  });

  it('returns null and never calls the API when offline', async () => {
    useOfflineStore.setState({ isOnline: false });
    expect(await runDuplicateTrip('t1')).toBeNull();
    expect(mockDuplicate).not.toHaveBeenCalled();
  });

  it('returns null when the backend duplication fails', async () => {
    mockDuplicate.mockResolvedValue(null);
    expect(await runDuplicateTrip('t1')).toBeNull();
  });

  it('returns null (never throws) when the request rejects', async () => {
    mockDuplicate.mockRejectedValue(new Error('boom'));
    expect(await runDuplicateTrip('t1')).toBeNull();
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
