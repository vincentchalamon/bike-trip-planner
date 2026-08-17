/// <reference types="jest" />
jest.mock('./client', () => ({ api: { GET: jest.fn() } }));
import { api } from './client';
import { fetchTrips, TRIPS_PAGE_SIZE } from './trips';

const mockGet = api.GET as jest.MockedFunction<typeof api.GET>;

beforeEach(() => jest.clearAllMocks());

describe('fetchTrips (#1036)', () => {
  it('requests the given page with itemsPerPage and only the non-empty filters', async () => {
    mockGet.mockResolvedValue({
      data: { member: [{ id: 't1' }], totalItems: 20 },
      error: undefined,
    } as never);

    const res = await fetchTrips(2, { title: 'alps', startDate: '2026-01-01', endDate: '' });

    expect(mockGet).toHaveBeenCalledWith('/trips', {
      params: { query: { page: 2, itemsPerPage: TRIPS_PAGE_SIZE, title: 'alps', startDate: '2026-01-01' } },
      headers: { Accept: 'application/ld+json' },
    });
    expect(res).toEqual({ items: [{ id: 't1' }], totalItems: 20 });
  });

  it('defaults to page 1 with no filters', async () => {
    mockGet.mockResolvedValue({ data: { member: [], totalItems: 0 }, error: undefined } as never);
    await fetchTrips();
    expect(mockGet).toHaveBeenCalledWith('/trips', {
      params: { query: { page: 1, itemsPerPage: TRIPS_PAGE_SIZE } },
      headers: { Accept: 'application/ld+json' },
    });
  });

  it('throws on a backend error so an empty list is not mistaken for a failure', async () => {
    mockGet.mockResolvedValue({ data: undefined, error: { detail: 'boom' } } as never);
    await expect(fetchTrips()).rejects.toThrow('Failed to fetch trips');
  });
});
