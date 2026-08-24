import { Directory, File, Paths } from 'expo-file-system';
// The next File API's write() is synchronous (blocks the JS thread while the whole
// ~6300-point geometry is serialized and flushed). The legacy writeAsStringAsync is
// the only async writer expo-file-system exposes, so heavy cache writes run off the
// UI thread (#1175).
import { writeAsStringAsync } from 'expo-file-system/legacy';
import type { TripDetail, TripRoute } from '../api/trips';
import { todayUtc, tripStateFromDates } from '../components/trip/roadbook-dates';

// Persistent offline cache for the trips the rider is likely to open without a
// connection: the /detail payload plus the static route geometry (#1147). It
// rides on expo-file-system's document directory (survives restarts, not evicted
// under storage pressure) rather than SecureStore — the route geometry is far too
// large for SecureStore's small-value ceiling, and none of this is sensitive.
//
// Detail and route live in two separate files per trip (`<id>.json` and
// `<id>.route.json`): a /detail refresh must not re-parse and re-serialize the
// large geometry just to preserve it, and a re-sync must not rewrite an unchanged
// route (#1175).
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

// On-disk shape of the `<id>.json` file. `route` is only present in legacy
// single-file caches written before the detail/route split; new writes never
// embed it (it lives in `<id>.route.json`).
interface CachedMeta {
  detail: TripDetail;
  syncedAt: number;
  route?: TripRoute | null;
}

const CACHE_DIRNAME = 'trip-cache';
const ROUTE_SUFFIX = '.route.json';
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

// Per-id mutation lock: cacheTripDetail and cacheTripRoute are read-modify-write
// on the same file, fired from independent unordered effects (roadbook vs map).
// Without serialization a detail refresh can read the pre-route entry and clobber
// a freshly attached route (lost update) — losing the offline trace. Chaining the
// mutations per id makes each one see the previous write.
const pending = new Map<string, Promise<unknown>>();
function withLock<T>(id: string, fn: () => Promise<T>): Promise<T> {
  const prior = pending.get(id) ?? Promise.resolve();
  const next = prior.then(fn, fn);
  pending.set(id, next.catch(() => undefined));
  return next;
}

function cacheDir(): Directory {
  return new Directory(Paths.document, CACHE_DIRNAME);
}

function metaFile(id: string): File {
  return new File(cacheDir(), `${id}.json`);
}

function routeFile(id: string): File {
  return new File(cacheDir(), `${id}${ROUTE_SUFFIX}`);
}

function ensureDir(): void {
  const dir = cacheDir();
  if (!dir.exists) dir.create({ intermediates: true });
}

// Asynchronous write, wrapped so a filesystem error never propagates: the cache is
// best-effort and the live network path is authoritative. create() is kept because
// it is a known offline fix (#1147) that guarantees the target exists before write.
async function writeFile(file: File, content: string): Promise<void> {
  try {
    ensureDir();
    if (!file.exists) file.create();
    await writeAsStringAsync(file.uri, content);
  } catch {
    // ignore: a failed cache write only costs a later offline miss.
  }
}

/** Read a trip's `<id>.json` metadata (detail + syncedAt), without the geometry. */
async function readMeta(id: string): Promise<CachedMeta | null> {
  try {
    const file = metaFile(id);
    if (!file.exists) return null;
    const parsed = JSON.parse(await file.text()) as CachedMeta;
    if (!parsed || typeof parsed.syncedAt !== 'number' || !parsed.detail) {
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

/** Read a trip's `<id>.route.json` geometry, or null when absent or corrupt. */
async function readRoute(id: string): Promise<TripRoute | null> {
  try {
    const file = routeFile(id);
    if (!file.exists) return null;
    return JSON.parse(await file.text()) as TripRoute;
  } catch {
    return null;
  }
}

/** Read a trip's cached entry, or null when it is absent or corrupt. */
export async function readTripCache(id: string): Promise<CachedTrip | null> {
  if (!SAFE_ID.test(id)) return null;
  const meta = await readMeta(id);
  if (!meta) return null;
  // Prefer the split route file; fall back to a legacy embedded route so a cache
  // written before the split still surfaces its geometry.
  const route = (await readRoute(id)) ?? meta.route ?? null;
  return { detail: meta.detail, route, syncedAt: meta.syncedAt };
}

/** Delete a trip's cache entry (e.g. once it turns past, or on trip deletion). */
export async function deleteTripCache(id: string): Promise<void> {
  if (!SAFE_ID.test(id)) return;
  try {
    const meta = metaFile(id);
    if (meta.exists) meta.delete();
    const route = routeFile(id);
    if (route.exists) route.delete();
  } catch {
    // ignore
  }
}

/**
 * Persist (or refresh) a trip's /detail payload and stamp the sync time. A trip
 * that is no longer cacheable (turned past) is evicted instead. The route file is
 * left untouched, so a detail refresh never re-parses or re-serializes the
 * geometry (#1175).
 */
export async function cacheTripDetail(
  id: string,
  detail: TripDetail,
  syncedAt: number = Date.now(),
): Promise<void> {
  if (!SAFE_ID.test(id)) return;
  return withLock(id, async () => {
    if (!isCacheableTrip(detail.startDate ?? null, detail.endDate ?? null)) {
      await deleteTripCache(id);
      return;
    }
    // Migrate a legacy embedded route (pre-split mono-file cache) into the
    // split route file before overwriting the meta, so a detail-only refresh
    // never silently drops a previously-cached geometry (readTripCache's
    // "compat mono-fichier" fallback stays honoured).
    const existing = await readMeta(id);
    if (existing?.route && !routeFile(id).exists) {
      await writeFile(routeFile(id), JSON.stringify(existing.route));
    }
    await writeFile(metaFile(id), JSON.stringify({ detail, syncedAt } satisfies CachedMeta));
  });
}

/**
 * Attach freshly fetched route geometry to a trip's cache entry and re-stamp the
 * sync time. No-op when the trip is not already cached (its /detail must be
 * cached first, which classifies it as cacheable). The (large) geometry is only
 * rewritten when it actually changed; the sync time is re-stamped regardless
 * (#1175).
 */
export async function cacheTripRoute(
  id: string,
  route: TripRoute,
  syncedAt: number = Date.now(),
): Promise<void> {
  if (!SAFE_ID.test(id)) return;
  return withLock(id, async () => {
    const meta = await readMeta(id);
    if (!meta) return;
    const serialized = JSON.stringify(route);
    const file = routeFile(id);
    const previous = file.exists ? await file.text() : null;
    if (previous !== serialized) await writeFile(file, serialized);
    await writeFile(metaFile(id), JSON.stringify({ detail: meta.detail, syncedAt } satisfies CachedMeta));
  });
}

/** Ids of every currently cached trip (drives the background re-sync). */
export async function listCachedTripIds(): Promise<string[]> {
  try {
    const dir = cacheDir();
    if (!dir.exists) return [];
    return dir
      .list()
      .map((entry) => entry.name)
      .filter((name) => name.endsWith('.json') && !name.endsWith(ROUTE_SUFFIX))
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
        // the detail refresh — a now-past trip was just evicted. Check the meta
        // file directly rather than reading (and parsing) the whole entry.
        if (metaFile(id).exists) {
          const route = await deps.fetchRoute(id);
          if (route) await cacheTripRoute(id, route);
        }
      } catch {
        // ignore this trip; the next sync pass retries.
      }
    }),
  );
}
