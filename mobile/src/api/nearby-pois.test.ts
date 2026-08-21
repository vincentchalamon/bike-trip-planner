/// <reference types="jest" />
jest.mock('./client', () => ({ api: { POST: jest.fn() } }));
import { api } from './client';
import { searchNearbyPois } from './nearby-pois';

const mockPost = api.POST as jest.MockedFunction<typeof api.POST>;

beforeEach(() => jest.clearAllMocks());

const okBody = {
  tripId: 't1',
  category: 'water',
  radiusMeters: 3000,
  totalFound: 2,
  capReached: false,
  outOfCoverage: false,
  pois: [{ name: 'Fontaine', category: 'water', lat: 45, lon: 6, distance_m: 120, deeplink: 'https://maps' }],
};

describe('searchNearbyPois (#1150)', () => {
  it('POSTs category + position (+ null radius/day by default) and returns the ok payload', async () => {
    mockPost.mockResolvedValue({ response: { ok: true, status: 200 }, data: okBody } as never);

    const res = await searchNearbyPois('t1', {
      category: 'water',
      position: { lat: 45, lon: 6 },
    });

    expect(res).toEqual({ status: 'ok', data: okBody });
    expect(mockPost).toHaveBeenCalledWith('/trips/{id}/nearby-pois', {
      params: { path: { id: 't1' } },
      headers: { Accept: 'application/ld+json', 'Content-Type': 'application/ld+json' },
      body: { category: 'water', position: { lat: 45, lon: 6 }, radiusMeters: null, stageDay: null },
    });
  });

  it('forwards an explicit widened radius and stage day', async () => {
    mockPost.mockResolvedValue({ response: { ok: true, status: 200 }, data: okBody } as never);

    await searchNearbyPois('t1', {
      category: 'food',
      position: { lat: 45, lon: 6 },
      radiusMeters: 6000,
      stageDay: 3,
    });

    const [, options] = mockPost.mock.calls[0] as [string, { body: Record<string, unknown> }];
    expect(options.body).toEqual({
      category: 'food',
      position: { lat: 45, lon: 6 },
      radiusMeters: 6000,
      stageDay: 3,
    });
  });

  it('classifies a 429 as rate_limited (no aggressive retry)', async () => {
    mockPost.mockResolvedValue({ response: { ok: false, status: 429 }, data: undefined } as never);
    const res = await searchNearbyPois('t1', { category: 'water', position: { lat: 45, lon: 6 } });
    expect(res).toEqual({ status: 'rate_limited' });
    expect(mockPost).toHaveBeenCalledTimes(1);
  });

  it('classifies a rejected request (offline / DNS) as network', async () => {
    mockPost.mockRejectedValue(new TypeError('Network request failed'));
    const res = await searchNearbyPois('t1', { category: 'water', position: { lat: 45, lon: 6 } });
    expect(res).toEqual({ status: 'network' });
  });

  it('classifies any other non-2xx as a generic error', async () => {
    mockPost.mockResolvedValue({ response: { ok: false, status: 500 }, data: undefined } as never);
    const res = await searchNearbyPois('t1', { category: 'water', position: { lat: 45, lon: 6 } });
    expect(res).toEqual({ status: 'error' });
  });
});
