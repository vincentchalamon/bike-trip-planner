import { useCallback, useEffect, useRef, useState } from 'react';
import { Alert } from 'react-native';
import {
  deleteTrip,
  duplicateTrip,
  fetchTrips,
  type TripFilters,
  type TripListItem,
} from '../api/trips';
import { useOfflineStore } from '../store/offline-store';

const DEBOUNCE_MS = 300;

export interface TripsPageResult {
  items: TripListItem[];
  totalItems: number;
  error: string | null;
}

// Extracted so the load/error branch is unit-testable without a React renderer
// (mirrors runLoadTripDetail, #1031). Never throws: a backend failure resolves to
// an empty page + an error message the caller surfaces.
export async function runLoadTrips(
  page: number,
  filters: TripFilters,
): Promise<TripsPageResult> {
  try {
    const { items, totalItems } = await fetchTrips(page, filters);
    return { items, totalItems, error: null };
  } catch {
    return { items: [], totalItems: 0, error: 'Impossible de charger les voyages.' };
  }
}

// Delete a trip. Returns null on success, or a message on failure (a non-owner is
// masked as 404 → still !ok, ADR-038). Never throws.
export async function runDeleteTrip(id: string): Promise<string | null> {
  const { ok } = await deleteTrip(id);
  return ok ? null : 'La suppression a échoué.';
}

// Duplicate a trip from the list (deep clone, allowed on a started/out-of-zone
// trip — it clones rather than edits — but not offline). Returns the new trip id
// on success, or null on failure. Never throws.
export async function runDuplicateTrip(id: string): Promise<string | null> {
  if (!useOfflineStore.getState().isOnline) {
    return null;
  }
  try {
    return await duplicateTrip(id);
  } catch {
    return null;
  }
}

/** More items on the server than we have loaded → another page is available. */
export function hasMorePages(loaded: number, totalItems: number): boolean {
  return loaded < totalItems;
}

/** Any active filter → distinguishes "no results" from "no trips at all". */
export function hasActiveFilter(filters: TripFilters): boolean {
  return Boolean(filters.title || filters.startDate || filters.endDate);
}

// Present the destructive confirm dialog; the delete itself runs in onConfirm.
// Extracted so the button wiring is unit-testable (mirrors RoadbookView, #1037).
export function confirmDeleteTrip(opts: {
  title: string;
  message: string;
  cancel: string;
  confirm: string;
  onConfirm: () => void;
}): void {
  Alert.alert(opts.title, opts.message, [
    { text: opts.cancel, style: 'cancel' },
    { text: opts.confirm, style: 'destructive', onPress: opts.onConfirm },
  ]);
}

export interface UseTrips {
  trips: TripListItem[];
  loading: boolean;
  loadingMore: boolean;
  refreshing: boolean;
  error: string | null;
  title: string;
  startDate: string;
  endDate: string;
  hasActiveFilter: boolean;
  canLoadMore: boolean;
  setTitle: (v: string) => void;
  setStartDate: (v: string) => void;
  setEndDate: (v: string) => void;
  reload: () => void;
  loadMore: () => void;
  remove: (id: string) => Promise<string | null>;
  duplicate: (id: string) => Promise<string | null>;
}

// Paginated (12/page), server-filtered trip list. Every filter (title and both
// dates) flows through one debounced filter object so a keystroke does not refetch
// and dates and title cannot race. Any filter change resets to page 1 and replaces
// the list; loadMore appends the next page. A request counter drops the response of
// a loadMore that was in flight when the filter/context changed.
export function useTrips(): UseTrips {
  const [title, setTitle] = useState('');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [debouncedFilters, setDebouncedFilters] = useState<TripFilters>({
    title: '',
    startDate: '',
    endDate: '',
  });

  const [trips, setTrips] = useState<TripListItem[]>([]);
  const [totalItems, setTotalItems] = useState(0);
  const [page, setPage] = useState(1);

  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [nonce, setNonce] = useState(0);

  // Bumped on every page-1 (re)load; a loadMore captures it and drops its response
  // if it no longer matches, i.e. the filter/context changed mid-flight.
  const requestId = useRef(0);

  useEffect(() => {
    const handle = setTimeout(() => {
      // Bail out on an unchanged filter (return prev) so no redundant page-1 refetch.
      setDebouncedFilters((prev) =>
        prev.title === title && prev.startDate === startDate && prev.endDate === endDate
          ? prev
          : { title, startDate, endDate },
      );
    }, DEBOUNCE_MS);
    return () => clearTimeout(handle);
  }, [title, startDate, endDate]);

  // Page 1 on mount and on every query change (debounced filters / reload).
  useEffect(() => {
    requestId.current += 1;
    let cancelled = false;
    setLoading(true);
    setLoadingMore(false);
    void runLoadTrips(1, debouncedFilters).then((res) => {
      if (cancelled) return;
      setTrips(res.items);
      setTotalItems(res.totalItems);
      setError(res.error);
      setPage(1);
      setLoading(false);
      setRefreshing(false);
    });
    return () => {
      cancelled = true;
    };
  }, [debouncedFilters, nonce]);

  const canLoadMore =
    !loading && !loadingMore && hasMorePages(trips.length, totalItems);

  const loadMore = useCallback(() => {
    if (loading || loadingMore || !hasMorePages(trips.length, totalItems)) return;
    const token = requestId.current;
    const next = page + 1;
    setLoadingMore(true);
    void runLoadTrips(next, debouncedFilters).then((res) => {
      if (requestId.current !== token) return; // stale: filters/context changed mid-flight
      setTrips((prev) => [...prev, ...res.items]);
      setTotalItems(res.totalItems);
      if (res.error) setError(res.error);
      setPage(next);
      setLoadingMore(false);
    });
  }, [loading, loadingMore, trips.length, totalItems, page, debouncedFilters]);

  const reload = useCallback(() => {
    setRefreshing(true);
    setNonce((n) => n + 1);
  }, []);

  const remove = useCallback(async (id: string): Promise<string | null> => {
    const err = await runDeleteTrip(id);
    if (!err) {
      setTrips((prev) => prev.filter((t) => t.id !== id));
      setTotalItems((n) => Math.max(0, n - 1));
    }
    return err;
  }, []);

  // Duplicate a trip; on success reload page 1 so the clone surfaces at the top
  // (the backend orders by creation date). Returns the new id, or null on failure.
  const duplicate = useCallback(async (id: string): Promise<string | null> => {
    const newId = await runDuplicateTrip(id);
    if (newId) {
      setNonce((n) => n + 1);
    }
    return newId;
  }, []);

  return {
    trips,
    loading,
    loadingMore,
    refreshing,
    error,
    title,
    startDate,
    endDate,
    hasActiveFilter: hasActiveFilter(debouncedFilters),
    canLoadMore,
    setTitle,
    setStartDate,
    setEndDate,
    reload,
    loadMore,
    remove,
    duplicate,
  };
}
