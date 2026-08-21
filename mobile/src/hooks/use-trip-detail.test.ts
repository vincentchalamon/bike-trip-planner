/// <reference types="jest" />
import { runLoadTripDetail } from './use-trip-detail';
import { useOfflineStore } from '../store/offline-store';

jest.mock('../api/trips', () => ({ fetchTripDetail: jest.fn() }));
jest.mock('../store/trip-cache', () => ({
  cacheTripDetail: jest.fn(),
  readTripCache: jest.fn(),
}));
import { fetchTripDetail } from '../api/trips';
import { cacheTripDetail, readTripCache } from '../store/trip-cache';
const mockDetail = fetchTripDetail as jest.MockedFunction<typeof fetchTripDetail>;
const mockCache = cacheTripDetail as jest.MockedFunction<typeof cacheTripDetail>;
const mockReadCache = readTripCache as jest.MockedFunction<typeof readTripCache>;

beforeEach(() => {
  jest.clearAllMocks();
  useOfflineStore.getState().setOnline(true);
  mockReadCache.mockResolvedValue(null);
});

describe('runLoadTripDetail (#1031)', () => {
  it('returns the detail on success and refreshes the cache', async () => {
    mockDetail.mockResolvedValue({ title: 'Trip' } as never);
    const { detail, error } = await runLoadTripDetail('t1');
    expect(detail).toEqual({ title: 'Trip' });
    expect(error).toBeNull();
    expect(mockCache).toHaveBeenCalledWith('t1', { title: 'Trip' });
  });

  it('reports "Voyage introuvable." when the detail is null', async () => {
    mockDetail.mockResolvedValue(null);
    const { detail, error } = await runLoadTripDetail('t1');
    expect(detail).toBeNull();
    expect(error).toBe('Voyage introuvable.');
  });

  it('reports a load error when the fetch throws and no cache exists', async () => {
    mockDetail.mockRejectedValue(new Error('boom'));
    const { error } = await runLoadTripDetail('t1');
    expect(error).toBe('Impossible de charger le voyage.');
  });
});

describe('runLoadTripDetail offline cache (#1147)', () => {
  it('serves the cache without hitting the network while offline', async () => {
    useOfflineStore.getState().setOnline(false);
    mockReadCache.mockResolvedValue({
      detail: { title: 'Cached' },
      route: null,
      syncedAt: 1,
    } as never);
    const { detail, error } = await runLoadTripDetail('t1');
    expect(detail).toEqual({ title: 'Cached' });
    expect(error).toBeNull();
    expect(mockDetail).not.toHaveBeenCalled();
  });

  it('falls back to the cache when the network fetch fails', async () => {
    mockDetail.mockRejectedValue(new Error('boom'));
    mockReadCache.mockResolvedValue({
      detail: { title: 'Cached' },
      route: null,
      syncedAt: 1,
    } as never);
    const { detail, error } = await runLoadTripDetail('t1');
    expect(detail).toEqual({ title: 'Cached' });
    expect(error).toBeNull();
  });
});
