import { Directory, File, Paths } from 'expo-file-system';
import { writeAsStringAsync } from 'expo-file-system/legacy';
import type { TripListItem } from '../api/trips';

// Last-known snapshot of the trips list (page 1, unfiltered), so the home screen
// still lists the rider's trips offline / when the API is down instead of a bare
// error (#1167). Stored as one JSON file in its OWN directory (never trip-cache/, whose
// id-based file enumeration would otherwise pick up trips-list.json as a bogus
// trip id and GET /trips/trips-list/detail forever), kept separate because: the list item shape (Trip.TripListItem) is not
// the detail payload, and reconstructing it from per-trip detail caches would be
// lossy (only opened trips are cached, and stageCount/totalDistance/status differ).

const CACHE_DIRNAME = 'trips-list-cache';
const LIST_FILENAME = 'trips-list.json';

// Serialize cache writes and the logout purge so they never interleave: an
// in-flight write always completes before the purge runs, and the purge then
// deletes whatever it wrote — so no trip title survives logout on a shared device
// (#1174 parity). A plain flag wouldn't help: clearCachedTripList has no await, so
// its window would be empty.
let chain: Promise<unknown> = Promise.resolve();
function withLock<T>(fn: () => Promise<T>): Promise<T> {
  const next = chain.then(fn, fn);
  chain = next.catch(() => undefined);
  return next;
}

interface CachedList {
  items: TripListItem[];
  syncedAt: number;
}

function cacheDir(): Directory {
  return new Directory(Paths.document, CACHE_DIRNAME);
}

function listFile(): File {
  return new File(cacheDir(), LIST_FILENAME);
}

/** Persist the current (page 1, unfiltered) trips list. Best-effort. */
export async function cacheTripList(
  items: TripListItem[],
  syncedAt: number = Date.now(),
): Promise<void> {
  return withLock(async () => {
    try {
      const dir = cacheDir();
      if (!dir.exists) dir.create({ intermediates: true });
      const file = listFile();
      if (!file.exists) file.create();
      await writeAsStringAsync(
        file.uri,
        JSON.stringify({ items, syncedAt } satisfies CachedList),
      );
    } catch {
      // ignore: a failed cache write only costs a later offline miss.
    }
  });
}

/** Read the cached trips list, or null when absent or corrupt. */
export async function readCachedTripList(): Promise<TripListItem[] | null> {
  try {
    const file = listFile();
    if (!file.exists) return null;
    const parsed = JSON.parse(await file.text()) as CachedList;
    return Array.isArray(parsed?.items) ? parsed.items : null;
  } catch {
    return null;
  }
}

/** Drop the cached list (called on logout so no trip titles survive — #1174). */
export async function clearCachedTripList(): Promise<void> {
  return withLock(async () => {
    try {
      const file = listFile();
      if (file.exists) file.delete();
    } catch {
      // ignore
    }
  });
}
