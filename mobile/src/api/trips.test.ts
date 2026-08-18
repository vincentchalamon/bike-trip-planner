/// <reference types="jest" />
jest.mock('./client', () => ({ api: { GET: jest.fn(), POST: jest.fn() } }));
import { api } from './client';
import { fetchTrips, TRIPS_PAGE_SIZE, uploadGpx } from './trips';

const mockGet = api.GET as jest.MockedFunction<typeof api.GET>;
const mockPost = api.POST as jest.MockedFunction<typeof api.POST>;

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

describe('uploadGpx (#1043)', () => {
  it('POSTs a multipart FormData carrying the picked file and returns id + status', async () => {
    mockPost.mockResolvedValue({
      data: { id: 'gpx-1' },
      response: { status: 202 },
    } as never);

    const res = await uploadGpx({
      uri: 'file:///tmp/route.gpx',
      name: 'route.gpx',
      mimeType: 'application/gpx+xml',
    });

    expect(res).toEqual({ id: 'gpx-1', status: 202 });
    expect(mockPost).toHaveBeenCalledTimes(1);
    const [path, options] = mockPost.mock.calls[0] as [
      string,
      { body: FormData },
    ];
    expect(path).toBe('/trips/gpx-upload');
    expect(options.body).toBeInstanceOf(FormData);
    // The multipart part is keyed `gpxFile` (the backend's expected field name).
    const part = options.body.get('gpxFile');
    expect(part).not.toBeNull();
  });

  it('defaults the mime type when the picker did not report one', async () => {
    mockPost.mockResolvedValue({ data: { id: 'gpx-2' }, response: { status: 202 } } as never);
    const res = await uploadGpx({ uri: 'file:///a.gpx', name: 'a.gpx' });
    expect(res.id).toBe('gpx-2');
  });

  it('returns a null id (with the raw status) on a rejected upload', async () => {
    mockPost.mockResolvedValue({ data: undefined, response: { status: 422 } } as never);
    const res = await uploadGpx({ uri: 'file:///bad.gpx', name: 'bad.gpx' });
    expect(res).toEqual({ id: null, status: 422 });
  });
});
