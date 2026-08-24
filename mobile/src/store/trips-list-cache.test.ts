/// <reference types="jest" />
import type { TripListItem } from '../api/trips';

const mockFiles = new Map<string, string>();
let mockDirExists = true;

jest.mock('expo-file-system', () => {
  class File {
    uri: string;
    constructor(dir: { uri: string }, name: string) {
      this.uri = `${dir.uri}/${name}`;
    }
    get exists() {
      return mockFiles.has(this.uri);
    }
    create() {
      if (!mockFiles.has(this.uri)) mockFiles.set(this.uri, '');
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
  }
  return { File, Directory, Paths: { document: { uri: 'file:///doc' } } };
});

jest.mock('expo-file-system/legacy', () => ({
  writeAsStringAsync: jest.fn((uri: string, content: string) => {
    mockFiles.set(uri, content);
    return Promise.resolve();
  }),
}));

import {
  cacheTripList,
  clearCachedTripList,
  readCachedTripList,
} from './trips-list-cache';

const URI = 'file:///doc/trips-list-cache/trips-list.json';
const items = [{ id: 'a', title: 'A' }] as unknown as TripListItem[];

beforeEach(() => {
  mockFiles.clear();
  mockDirExists = true;
});

describe('trips-list-cache (#1167)', () => {
  it('caches in its OWN directory (never trip-cache/, to avoid the id collision)', async () => {
    await cacheTripList(items, 5);
    expect(mockFiles.has(URI)).toBe(true);
    expect([...mockFiles.keys()].some((k) => k.includes('/trip-cache/'))).toBe(false);
  });

  it('reads back what was cached, and null when absent', async () => {
    expect(await readCachedTripList()).toBeNull();
    await cacheTripList(items, 5);
    expect(await readCachedTripList()).toEqual(items);
  });

  it('clearCachedTripList deletes the snapshot', async () => {
    await cacheTripList(items, 5);
    await clearCachedTripList();
    expect(await readCachedTripList()).toBeNull();
  });

  it('an in-flight cache write does not survive a concurrent logout purge (#1174)', async () => {
    // The write is in flight (queued) when logout fires: serialization makes the
    // purge run AFTER it and delete what it wrote, so nothing survives.
    await Promise.all([cacheTripList(items, 5), clearCachedTripList()]);
    expect(await readCachedTripList()).toBeNull();
  });
});
