/// <reference types="jest" />
import type { TripDetail, TripRoute } from '../api/trips';

// In-memory filesystem backing the mocked expo-file-system. Keyed by file uri.
const mockFiles = new Map<string, string>();
let mockDirExists = true;

// Minimal File/Directory/Paths doubles matching the expo-file-system next API
// (create/write/text/exists/delete + Directory.list) used by trip-cache.
jest.mock('expo-file-system', () => {
  class File {
    uri: string;
    name: string;
    constructor(dir: { uri: string }, name: string) {
      this.uri = `${dir.uri}/${name}`;
      this.name = name;
    }
    get exists() {
      return mockFiles.has(this.uri);
    }
    create() {
      if (!mockFiles.has(this.uri)) mockFiles.set(this.uri, '');
    }
    write(content: string) {
      // Faithful to expo-file-system's next API: write() requires the file to
      // already exist (create() must be called first), it does not auto-create.
      if (!mockFiles.has(this.uri)) {
        throw new Error(`ENOENT: file does not exist, write '${this.uri}'`);
      }
      mockFiles.set(this.uri, content);
    }
    text() {
      return Promise.resolve(mockFiles.get(this.uri) ?? '');
    }
    delete() {
      mockFiles.delete(this.uri);
    }
  }
  class Directory {
    uri: string;
    constructor(parent: { uri: string }, name: string) {
      this.uri = `${parent.uri}/${name}`;
    }
    get exists() {
      return mockDirExists;
    }
    create() {
      mockDirExists = true;
    }
    list() {
      const prefix = `${this.uri}/`;
      return [...mockFiles.keys()]
        .filter((uri) => uri.startsWith(prefix))
        .map((uri) => ({ name: uri.slice(prefix.length) }));
    }
  }
  return { File, Directory, Paths: { document: { uri: 'file:///doc' } } };
});

// trip-cache now writes via the legacy async writer; back it with the same
// in-memory filesystem and expose it as a jest.fn so tests can assert what got
// (re)written.
jest.mock('expo-file-system/legacy', () => ({
  writeAsStringAsync: jest.fn((uri: string, content: string) => {
    mockFiles.set(uri, content);
    return Promise.resolve();
  }),
}));

import { writeAsStringAsync } from 'expo-file-system/legacy';
import {
  cacheTripDetail,
  cacheTripRoute,
  clearAllTripCache,
  deleteTripCache,
  isCacheableTrip,
  listCachedTripIds,
  readTripCache,
  syncCachedTrips,
} from './trip-cache';

// A fixed "today" so the lifecycle classification is deterministic.
const TODAY = '2026-08-21';

// Far-future dates so cacheTripDetail (which classifies against the real "today")
// always sees these as upcoming, regardless of when the suite runs.
function detail(over: Partial<TripDetail> = {}): TripDetail {
  return { title: 'Trip', startDate: '2099-09-01', endDate: '2099-09-05', ...over } as TripDetail;
}

const route = { stages: [{ dayNumber: 1, geometry: [] }] } as unknown as TripRoute;
const writeMock = writeAsStringAsync as jest.Mock;
const ROUTE_URI = (id: string) => `file:///doc/trip-cache/${id}.route.json`;
const META_URI = (id: string) => `file:///doc/trip-cache/${id}.json`;

beforeEach(() => {
  mockFiles.clear();
  mockDirExists = true;
  writeMock.mockClear();
});

describe('isCacheableTrip', () => {
  it('caches upcoming and ongoing trips', () => {
    expect(isCacheableTrip('2026-09-01', '2026-09-05', TODAY)).toBe(true); // upcoming
    expect(isCacheableTrip('2026-08-20', '2026-08-25', TODAY)).toBe(true); // ongoing
  });
  it('caches an undated (draft) trip', () => {
    expect(isCacheableTrip(null, null, TODAY)).toBe(true);
  });
  it('excludes a past trip', () => {
    expect(isCacheableTrip('2026-07-01', '2026-07-10', TODAY)).toBe(false);
  });
});

describe('cacheTripDetail / readTripCache', () => {
  it('writes an upcoming trip and reads it back with a timestamp', async () => {
    await cacheTripDetail('trip-1', detail(), 1234);
    const cached = await readTripCache('trip-1');
    expect(cached?.detail.title).toBe('Trip');
    expect(cached?.syncedAt).toBe(1234);
    expect(cached?.route).toBeNull();
  });

  it('persists on the very first write for a trip id (no pre-existing file)', async () => {
    // No prior create()/write() for this id: writeFile must create the file
    // before the async write for the entry to land.
    expect(mockFiles.size).toBe(0);
    await cacheTripDetail('brand-new', detail(), 999);
    const cached = await readTripCache('brand-new');
    expect(cached?.syncedAt).toBe(999);
  });

  it('writes asynchronously via writeAsStringAsync', async () => {
    await cacheTripDetail('async-1', detail(), 1);
    expect(writeMock).toHaveBeenCalledWith(META_URI('async-1'), expect.any(String));
  });

  it('reads back a legacy single-file entry with an embedded route', async () => {
    // Cache written before the detail/route split embedded the route in <id>.json.
    mockFiles.set(
      META_URI('legacy'),
      JSON.stringify({ detail: detail(), route, syncedAt: 7 }),
    );
    const cached = await readTripCache('legacy');
    expect(cached?.route).toEqual(route);
    expect(cached?.syncedAt).toBe(7);
  });

  it('migrates a legacy embedded route into the split file on a detail-only refresh (no drop)', async () => {
    // Pre-split cache: route embedded in <id>.json, no <id>.route.json yet.
    mockFiles.set(
      META_URI('mig'),
      JSON.stringify({ detail: detail(), route, syncedAt: 7 }),
    );
    expect(mockFiles.has(ROUTE_URI('mig'))).toBe(false);
    // A detail-only refresh must NOT drop the previously-cached geometry.
    await cacheTripDetail('mig', detail(), 8);
    expect(mockFiles.has(ROUTE_URI('mig'))).toBe(true);
    const cached = await readTripCache('mig');
    expect(cached?.route).toEqual(route);
    expect(cached?.syncedAt).toBe(8);
  });

  it('keeps the legacy route in the meta when the migration write fails (no silent drop)', async () => {
    mockFiles.set(
      META_URI('mig2'),
      JSON.stringify({ detail: detail(), route, syncedAt: 7 }),
    );
    // Fail the route-file migration write specifically (disk full / native error).
    // create() still leaves an empty file on disk, so `.exists` would lie —
    // only writeFile's return value can confirm the write actually landed.
    writeMock.mockImplementationOnce((uri: string, content: string) => {
      if (uri === ROUTE_URI('mig2')) return Promise.reject(new Error('disk full'));
      mockFiles.set(uri, content);
      return Promise.resolve();
    });
    await cacheTripDetail('mig2', detail(), 8);
    // Route not persisted to the split file, but preserved via the meta fallback.
    const cached = await readTripCache('mig2');
    expect(cached?.route).toEqual(route);
  });

  it('re-attempts a failed migration on the next refresh (empty stub is not "migrated")', async () => {
    mockFiles.set(
      META_URI('stub'),
      JSON.stringify({ detail: detail(), route, syncedAt: 7 }),
    );
    // 1st refresh: migration write fails → empty stub left on disk, route kept in meta.
    writeMock.mockImplementationOnce((uri: string, content: string) => {
      if (uri === ROUTE_URI('stub')) return Promise.reject(new Error('disk full'));
      mockFiles.set(uri, content);
      return Promise.resolve();
    });
    await cacheTripDetail('stub', detail(), 8);
    expect(mockFiles.get(ROUTE_URI('stub'))).toBe(''); // empty stub from create()
    expect((await readTripCache('stub'))?.route).toEqual(route);
    // 2nd refresh: the stub's content ('') != the route, so migration retries and
    // now succeeds — the route must NOT be dropped by trusting `.exists`.
    await cacheTripDetail('stub', detail(), 9);
    expect((await readTripCache('stub'))?.route).toEqual(route);
  });

  it('does not cache a past trip', async () => {
    await cacheTripDetail('past-1', detail({ startDate: '2000-01-01', endDate: '2000-01-05' }));
    expect(await readTripCache('past-1')).toBeNull();
  });

  it('evicts a cached trip that has turned past', async () => {
    await cacheTripDetail('trip-2', detail());
    expect(await readTripCache('trip-2')).not.toBeNull();
    await cacheTripDetail('trip-2', detail({ startDate: '2000-01-01', endDate: '2000-01-05' }));
    expect(await readTripCache('trip-2')).toBeNull();
  });

  it('rejects an unsafe id (no file escapes the cache dir)', async () => {
    await cacheTripDetail('../evil', detail());
    expect(await readTripCache('../evil')).toBeNull();
    expect(mockFiles.size).toBe(0);
  });

  it('returns null on a corrupt entry', async () => {
    mockFiles.set('file:///doc/trip-cache/broken.json', '{not json');
    expect(await readTripCache('broken')).toBeNull();
  });
});

describe('cacheTripRoute', () => {
  it('attaches geometry to an existing entry and preserves the detail', async () => {
    await cacheTripDetail('trip-3', detail(), 10);
    await cacheTripRoute('trip-3', route, 20);
    const cached = await readTripCache('trip-3');
    expect(cached?.route).toEqual(route);
    expect(cached?.detail.title).toBe('Trip');
    expect(cached?.syncedAt).toBe(20);
  });

  it('is a no-op when the trip is not already cached', async () => {
    await cacheTripRoute('missing', route);
    expect(await readTripCache('missing')).toBeNull();
  });

  it('keeps the legacy route in the meta when its own route write fails (no silent drop)', async () => {
    // Pre-split cache (route embedded in the meta), then a map open triggers
    // cacheTripRoute whose split-file write fails: the geometry must survive.
    mockFiles.set(
      META_URI('r2'),
      JSON.stringify({ detail: detail(), route, syncedAt: 7 }),
    );
    const other = { stages: [{ dayNumber: 2, geometry: [[3, 4]] }] } as unknown as TripRoute;
    writeMock.mockImplementationOnce((uri: string, content: string) => {
      if (uri === ROUTE_URI('r2')) return Promise.reject(new Error('disk full'));
      mockFiles.set(uri, content);
      return Promise.resolve();
    });
    await cacheTripRoute('r2', other, 8);
    expect((await readTripCache('r2'))?.route).toEqual(route);
  });

  it('does not rewrite the route file on a detail-only refresh (#1175)', async () => {
    await cacheTripDetail('t', detail(), 1);
    await cacheTripRoute('t', route, 2);
    writeMock.mockClear();
    await cacheTripDetail('t', detail({ title: 'Refreshed' }), 3);
    expect(writeMock.mock.calls.some(([uri]) => uri === ROUTE_URI('t'))).toBe(false);
    const cached = await readTripCache('t');
    expect(cached?.route).toEqual(route);
    expect(cached?.detail.title).toBe('Refreshed');
  });

  it('does not READ the large route file on a steady-state detail refresh (#1175 perf)', async () => {
    await cacheTripDetail('t', detail(), 1);
    await cacheTripRoute('t', route, 2); // split-file cache, no legacy route in meta
    const { File } = jest.requireMock('expo-file-system');
    const textSpy = jest.spyOn(File.prototype, 'text');
    await cacheTripDetail('t', detail({ title: 'Refreshed' }), 3);
    // The migration path must stay gated on a legacy route: a post-split refresh
    // reads only the meta, never the (~6300-point) route file.
    const routeReads = (textSpy.mock.instances as { uri: string }[]).filter((f) =>
      f.uri.endsWith('.route.json'),
    ).length;
    textSpy.mockRestore();
    expect(routeReads).toBe(0);
  });

  it('skips the route write when the geometry is unchanged but re-stamps syncedAt (#1175)', async () => {
    await cacheTripDetail('t', detail(), 1);
    await cacheTripRoute('t', route, 2);
    writeMock.mockClear();
    await cacheTripRoute('t', route, 3); // identical geometry
    expect(writeMock.mock.calls.some(([uri]) => uri === ROUTE_URI('t'))).toBe(false);
    expect((await readTripCache('t'))?.syncedAt).toBe(3);
  });

  it('rewrites the route file when the geometry actually changed', async () => {
    await cacheTripDetail('t', detail(), 1);
    await cacheTripRoute('t', route, 2);
    writeMock.mockClear();
    const other = { stages: [{ dayNumber: 1, geometry: [[1, 2]] }] } as unknown as TripRoute;
    await cacheTripRoute('t', other, 3);
    expect(writeMock.mock.calls.some(([uri]) => uri === ROUTE_URI('t'))).toBe(true);
    expect((await readTripCache('t'))?.route).toEqual(other);
  });

  it('does not drop the route when a route write races a detail refresh (#1148)', async () => {
    // Trip already cached from a prior open.
    await cacheTripDetail('race', detail(), 1);
    // Route write and a detail refresh fire concurrently, route first. Without
    // per-id serialization the detail refresh reads the pre-route entry and its
    // write clobbers the freshly attached route (lost update) — the offline
    // trace vanishes. The lock makes the detail write see the route write.
    await Promise.all([
      cacheTripRoute('race', route, 2),
      cacheTripDetail('race', detail({ title: 'Refreshed' }), 3),
    ]);
    const cached = await readTripCache('race');
    expect(cached?.route).toEqual(route);
    expect(cached?.detail.title).toBe('Refreshed');
  });
});

describe('listCachedTripIds / deleteTripCache', () => {
  it('lists cached ids and drops one on delete', async () => {
    await cacheTripDetail('a', detail());
    await cacheTripDetail('b', detail());
    expect((await listCachedTripIds()).sort()).toEqual(['a', 'b']);
    await deleteTripCache('a');
    expect(await listCachedTripIds()).toEqual(['b']);
  });
});

describe('clearAllTripCache (#1174)', () => {
  it('purges every cached trip', async () => {
    await cacheTripDetail('a', detail());
    await cacheTripDetail('b', detail());
    await cacheTripRoute('b', route);
    expect((await listCachedTripIds()).length).toBe(2);
    await clearAllTripCache();
    expect(await listCachedTripIds()).toEqual([]);
    expect(await readTripCache('a')).toBeNull();
    expect(await readTripCache('b')).toBeNull();
  });

  it('is a no-op when nothing is cached', async () => {
    await expect(clearAllTripCache()).resolves.toBeUndefined();
    expect(await listCachedTripIds()).toEqual([]);
  });
});

describe('syncCachedTrips', () => {
  it('re-fetches cached trips when online and refreshes detail + route', async () => {
    await cacheTripDetail('trip-4', detail({ title: 'Old' }), 1);
    const fetchDetail = jest.fn().mockResolvedValue(detail({ title: 'New' }));
    const fetchRoute = jest.fn().mockResolvedValue(route);
    await syncCachedTrips({ isOnline: () => true, fetchDetail, fetchRoute });
    expect(fetchDetail).toHaveBeenCalledWith('trip-4');
    const cached = await readTripCache('trip-4');
    expect(cached?.detail.title).toBe('New');
    expect(cached?.route).toEqual(route);
  });

  it('bails out and fetches nothing while offline', async () => {
    await cacheTripDetail('trip-5', detail());
    const fetchDetail = jest.fn();
    const fetchRoute = jest.fn();
    await syncCachedTrips({ isOnline: () => false, fetchDetail, fetchRoute });
    expect(fetchDetail).not.toHaveBeenCalled();
  });

  it('swallows a per-trip fetch failure', async () => {
    await cacheTripDetail('trip-6', detail());
    const fetchDetail = jest.fn().mockRejectedValue(new Error('offline'));
    const fetchRoute = jest.fn();
    await expect(
      syncCachedTrips({ isOnline: () => true, fetchDetail, fetchRoute }),
    ).resolves.toBeUndefined();
  });
});
