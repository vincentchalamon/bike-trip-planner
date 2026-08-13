import { useCallback, useEffect, useState } from 'react';
import { Alert } from 'react-native';
import {
  deleteTrip,
  fetchTrips,
  type TripFilters,
  type TripListItem,
} from '../api/trips';

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
}

// Paginated (12/page), server-filtered trip list. The title filter is debounced so
// a keystroke does not refetch; date filters apply immediately. Any filter change
// resets to page 1 and replaces the list; loadMore appends the next page.
export function useTrips(): UseTrips {
  const [title, setTitle] = useState('');
  const [debouncedTitle, setDebouncedTitle] = useState('');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');

  const [trips, setTrips] = useState<TripListItem[]>([]);
  const [totalItems, setTotalItems] = useState(0);
  const [page, setPage] = useState(1);

  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [nonce, setNonce] = useState(0);

  useEffect(() => {
    const handle = setTimeout(() => setDebouncedTitle(title), DEBOUNCE_MS);
    return () => clearTimeout(handle);
  }, [title]);

  // Page 1 on mount and on every query change (debounced title / dates / reload).
  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    void runLoadTrips(1, { title: debouncedTitle, startDate, endDate }).then((res) => {
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
  }, [debouncedTitle, startDate, endDate, nonce]);

  const canLoadMore =
    !loading && !loadingMore && hasMorePages(trips.length, totalItems);

  const loadMore = useCallback(() => {
    if (loading || loadingMore || !hasMorePages(trips.length, totalItems)) return;
    const next = page + 1;
    setLoadingMore(true);
    void runLoadTrips(next, { title: debouncedTitle, startDate, endDate }).then((res) => {
      setTrips((prev) => [...prev, ...res.items]);
      setTotalItems(res.totalItems);
      if (res.error) setError(res.error);
      setPage(next);
      setLoadingMore(false);
    });
  }, [loading, loadingMore, trips.length, totalItems, page, debouncedTitle, startDate, endDate]);

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

  return {
    trips,
    loading,
    loadingMore,
    refreshing,
    error,
    title,
    startDate,
    endDate,
    hasActiveFilter: hasActiveFilter({ title: debouncedTitle, startDate, endDate }),
    canLoadMore,
    setTitle,
    setStartDate,
    setEndDate,
    reload,
    loadMore,
    remove,
  };
}
