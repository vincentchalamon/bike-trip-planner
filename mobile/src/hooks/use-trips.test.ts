/// <reference types="jest" />
import { runLoadTrips } from './use-trips';

jest.mock('../api/trips', () => ({ fetchTrips: jest.fn() }));
import { fetchTrips } from '../api/trips';
const mockFetch = fetchTrips as jest.MockedFunction<typeof fetchTrips>;

beforeEach(() => jest.clearAllMocks());

describe('runLoadTrips (#1031)', () => {
  it('returns the list on success', async () => {
    mockFetch.mockResolvedValue([{ id: 't1' }] as never);
    const { trips, error } = await runLoadTrips();
    expect(trips).toHaveLength(1);
    expect(error).toBeNull();
  });

  it('returns an empty list + a message when the fetch throws', async () => {
    mockFetch.mockRejectedValue(new Error('boom'));
    const { trips, error } = await runLoadTrips();
    expect(trips).toEqual([]);
    expect(error).toBe('Impossible de charger les voyages.');
  });
});
