import { Directory, File, Paths } from 'expo-file-system';
import type { TripDetail, TripRoute } from '../api/trips';
import { todayUtc, tripStateFromDates } from '../components/trip/roadbook-dates';

// Persistent offline cache for the trips the rider is likely to open without a
// connection: the /detail payload plus the static route geometry (#1147). It
// rides on expo-file-system's document directory (survives restarts, not evicted
// under storage pressure) rather than SecureStore — the route geometry is far too
// large for SecureStore's small-value ceiling, and none of this is sensitive.
//
// Only upcoming / ongoing (and still-undated) trips are cached; a trip that has
// turned *past* is evicted and served online-only, which bounds on-device storage
// (a rider accumulates finished trips indefinitely). The lifecycle classifier is
// reused from the roadbook rather than duplicated.

export interface CachedTrip {
  /** The last /detail payload fetched online. */
  detail: TripDetail;
  /** The last /route geometry fetched online, or null before the map was opened. */
  route: TripRoute | null;
  /** Epoch ms of the last successful online sync (drives "synced X ago"). */
  syncedAt: number;
}

const CACHE_DIRNAME = 'trip-cache';
// Trip ids are backend-issued UUID/ULID-like; reject anything else so a crafted id
// can never escape the cache directory via the filename.
const SAFE_ID = /^[A-Za-z0-9_-]+$/;

/**
 * Whether a trip is eligible for the offline cache: everything except a *past*
 * trip (upcoming, ongoing, or still-undated). Past trips stay online-only to
 * bound storage. Reuses {@link tripStateFromDates} instead of re-deriving the
 * date math.
 */
export function isCacheableTrip(
  startDate: string | null,
  endDate: string | null,
  today: string = todayUtc(),
): boolean {
  return tripStateFromDates(startDate, endDate, today) !== 'past';
}

function cacheDir(): Directory {
  return new Directory(Paths.document, CACHE_DIRNAME);
}

function cacheFile(id: string): File {
  return new File(cacheDir(), `${id}.json`);
}

function ensureDir(): void {
  const dir = cacheDir();
  if (!dir.exists) dir.create({ intermediates: true });
}

// Synchronous write, wrapped so a filesystem error never propagates: the cache is
// best-effort and the live network path is authoritative.
function writeEntry(id: string, entry: CachedTrip): void {
  try {
    ensureDir();
    cacheFile(id).write(JSON.stringify(entry));
  } catch {
    // ignore: a failed cache write only costs a later offline miss.
  }
}

/** Read a trip's cached entry, or null when it is absent or corrupt. */
export async function readTripCache(id: string): Promise<CachedTrip | null> {
  if (!SAFE_ID.test(id)) return null;
  try {
    const file = cacheFile(id);
    if (!file.exists) return null;
    const parsed = JSON.parse(await file.text()) as CachedTrip;
    if (!parsed || typeof parsed.syncedAt !== 'number' || !parsed.detail) {
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

/** Delete a trip's cache entry (e.g. once it turns past, or on trip deletion). */
export async function deleteTripCache(id: string): Promise<void> {
  if (!SAFE_ID.test(id)) return;
  try {
    const file = cacheFile(id);
    if (file.exists) file.delete();
  } catch {
    // ignore
  }
}

/**
 * Persist (or refresh) a trip's /detail payload and stamp the sync time. A trip
 * that is no longer cacheable (turned past) is evicted instead. Any existing
 * route geometry is preserved.
 */
export async function cacheTripDetail(
  id: string,
  detail: TripDetail,
  syncedAt: number = Date.now(),
): Promise<void> {
  if (!SAFE_ID.test(id)) return;
  if (!isCacheableTrip(detail.startDate ?? null, detail.endDate ?? null)) {
    await deleteTripCache(id);
    return;
  }
  const existing = await readTripCache(id);
  writeEntry(id, { detail, route: existing?.route ?? null, syncedAt });
}

/**
 * Attach freshly fetched route geometry to a trip's cache entry and re-stamp the
 * sync time. No-op when the trip is not already cached (its /detail must be
 * cached first, which classifies it as cacheable).
 */
export async function cacheTripRoute(
  id: string,
  route: TripRoute,
  syncedAt: number = Date.now(),
): Promise<void> {
  if (!SAFE_ID.test(id)) return;
  const existing = await readTripCache(id);
  if (!existing) return;
  writeEntry(id, { ...existing, route, syncedAt });
}

/** Ids of every currently cached trip (drives the background re-sync). */
export async function listCachedTripIds(): Promise<string[]> {
  try {
    const dir = cacheDir();
    if (!dir.exists) return [];
    return dir
      .list()
      .map((entry) => entry.name)
      .filter((name) => name.endsWith('.json'))
      .map((name) => name.slice(0, -'.json'.length))
      .filter((id) => SAFE_ID.test(id));
  } catch {
    return [];
  }
}

/** Injectable dependencies for {@link syncCachedTrips} (kept out for testing). */
export interface SyncDeps {
  isOnline: () => boolean;
  fetchDetail: (id: string) => Promise<TripDetail | null>;
  fetchRoute: (id: string) => Promise<TripRoute | null>;
}

/**
 * Silently re-fetch every cached trip's /detail (then /route) so the offline copy
 * stays fresh. Triggered on return-to-online and app foreground. Best-effort: it
 * bails out while offline and swallows per-trip failures (still unreachable, or
 * deleted server-side). A trip that has turned past is evicted by cacheTripDetail.
 */
export async function syncCachedTrips(deps: SyncDeps): Promise<void> {
  if (!deps.isOnline()) return;
  const ids = await listCachedTripIds();
  await Promise.all(
    ids.map(async (id) => {
      try {
        const detail = await deps.fetchDetail(id);
        if (!detail) return;
        await cacheTripDetail(id, detail);
        // Only pull the (large) geometry back if the trip is still cached after
        // the detail refresh — a now-past trip was just evicted.
        if (await readTripCache(id)) {
          const route = await deps.fetchRoute(id);
          if (route) await cacheTripRoute(id, route);
        }
      } catch {
        // ignore this trip; the next sync pass retries.
      }
    }),
  );
}
