/// <reference types="jest" />
jest.mock('./client', () => ({ api: { GET: jest.fn(), POST: jest.fn() } }));
import { api } from './client';
import {
  fetchSharedTrip,
  fetchSharedTripExport,
  fetchSharedTripRoute,
  fetchStageExport,
  fetchTripExport,
  fetchTrips,
  stageExportFileName,
  tripExportFileName,
  TRIPS_PAGE_SIZE,
  uploadGpx,
} from './trips';

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

describe('export filenames (#1047)', () => {
  it('sanitizes the trip title and appends the format', () => {
    expect(tripExportFileName('Entre Sensée et Escaut', 'gpx')).toBe(
      'Entre-Sens-e-et-Escaut.gpx',
    );
    expect(tripExportFileName('   ', 'fit')).toBe('trip.fit');
  });

  it('appends the stage day number before the format', () => {
    expect(stageExportFileName('Entre Sensée et Escaut', 3, 'fit')).toBe(
      'Entre-Sens-e-et-Escaut-stage-3.fit',
    );
  });
});

describe('fetchTripExport (#1047)', () => {
  it('negotiates on Accept and reads the response as raw bytes', async () => {
    const bytes = new ArrayBuffer(4);
    mockGet.mockResolvedValue({
      data: bytes,
      error: undefined,
      response: { ok: true },
    } as never);

    const res = await fetchTripExport('trip-1', 'gpx');

    expect(mockGet).toHaveBeenCalledWith('/trips/{id}', {
      params: { path: { id: 'trip-1' } },
      headers: { Accept: 'application/gpx+xml' },
      parseAs: 'arrayBuffer',
    });
    expect(res).toBe(bytes);
  });

  it('throws when the backend responds with an error', async () => {
    mockGet.mockResolvedValue({
      data: undefined,
      error: { detail: 'boom' },
      response: { ok: false },
    } as never);
    await expect(fetchTripExport('trip-1', 'fit')).rejects.toThrow('Failed to export trip');
  });
});

describe('fetchStageExport (#1047)', () => {
  it('negotiates on Accept for the FIT mime type and reads raw bytes', async () => {
    const bytes = new ArrayBuffer(8);
    mockGet.mockResolvedValue({
      data: bytes,
      error: undefined,
      response: { ok: true },
    } as never);

    const res = await fetchStageExport('trip-1', 2, 'fit');

    expect(mockGet).toHaveBeenCalledWith('/trips/{tripId}/stages/{index}/export', {
      params: { path: { tripId: 'trip-1', index: '2' } },
      headers: { Accept: 'application/vnd.ant.fit' },
      parseAs: 'arrayBuffer',
    });
    expect(res).toBe(bytes);
  });

  it('throws when the backend responds with an error', async () => {
    mockGet.mockResolvedValue({
      data: undefined,
      error: { detail: 'boom' },
      response: { ok: false },
    } as never);
    await expect(fetchStageExport('trip-1', 2, 'gpx')).rejects.toThrow('Failed to export stage');
  });
});

describe('anonymous share fetches (#1177)', () => {
  it('fetchSharedTrip requests /s/{shortCode} and returns the payload, null on error', async () => {
    mockGet.mockResolvedValueOnce({ data: { title: 't' }, error: undefined } as never);
    await expect(fetchSharedTrip('AB12cd')).resolves.toEqual({ title: 't' });
    expect(mockGet).toHaveBeenCalledWith('/s/{shortCode}', {
      params: { path: { shortCode: 'AB12cd' } },
      headers: { Accept: 'application/ld+json' },
    });

    mockGet.mockResolvedValueOnce({ data: undefined, error: { detail: 'boom' } } as never);
    await expect(fetchSharedTrip('AB12cd')).resolves.toBeNull();
  });

  it('fetchSharedTripRoute requests /s/{shortCode}/route and returns null on error', async () => {
    mockGet.mockResolvedValueOnce({ data: { points: [] }, error: undefined } as never);
    await expect(fetchSharedTripRoute('AB12cd')).resolves.toEqual({ points: [] });
    expect(mockGet).toHaveBeenCalledWith('/s/{shortCode}/route', {
      params: { path: { shortCode: 'AB12cd' } },
      headers: { Accept: 'application/ld+json' },
    });

    mockGet.mockResolvedValueOnce({ data: undefined, error: { detail: 'boom' } } as never);
    await expect(fetchSharedTripRoute('AB12cd')).resolves.toBeNull();
  });

  it('fetchSharedTripExport hits the literal .gpx/.fit path and throws on failure', async () => {
    const bytes = new ArrayBuffer(4);
    mockGet.mockResolvedValueOnce({ data: bytes, error: undefined, response: { ok: true } } as never);
    await expect(fetchSharedTripExport('AB12cd', 'gpx')).resolves.toBe(bytes);
    expect(mockGet).toHaveBeenCalledWith('/s/{shortCode}.gpx', {
      params: { path: { shortCode: 'AB12cd' } },
      headers: { Accept: 'application/gpx+xml' },
      parseAs: 'arrayBuffer',
    });

    mockGet.mockResolvedValueOnce({
      data: undefined,
      error: { detail: 'boom' },
      response: { ok: false },
    } as never);
    await expect(fetchSharedTripExport('AB12cd', 'fit')).rejects.toThrow('Failed to export shared trip');
  });
});
