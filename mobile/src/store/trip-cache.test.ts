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

import {
  cacheTripDetail,
  cacheTripRoute,
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

beforeEach(() => {
  mockFiles.clear();
  mockDirExists = true;
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
    // No prior create()/write() for this id: the mock File.write() throws unless
    // the file was created first, so this only passes if writeEntry creates it.
    expect(mockFiles.size).toBe(0);
    await cacheTripDetail('brand-new', detail(), 999);
    const cached = await readTripCache('brand-new');
    expect(cached?.syncedAt).toBe(999);
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
