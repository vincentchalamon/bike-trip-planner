/// <reference types="jest" />
import {
  isSupportedSourceUrl,
  pickGpxFile,
  runCreateTrip,
  runUploadGpx,
  SUPPORTED_SOURCE_PATTERNS,
} from './create-trip';
import { useOfflineStore } from './offline-store';

jest.mock('../api/trips', () => ({ createTrip: jest.fn(), uploadGpx: jest.fn() }));
import { createTrip, uploadGpx } from '../api/trips';
const mockCreate = createTrip as jest.MockedFunction<typeof createTrip>;
const mockUpload = uploadGpx as jest.MockedFunction<typeof uploadGpx>;

jest.mock('expo-document-picker', () => ({ getDocumentAsync: jest.fn() }));
import * as DocumentPicker from 'expo-document-picker';
const mockPick = DocumentPicker.getDocumentAsync as jest.MockedFunction<
  typeof DocumentPicker.getDocumentAsync
>;

beforeEach(() => {
  jest.clearAllMocks();
  useOfflineStore.setState({ isOnline: true });
});

describe('isSupportedSourceUrl (mirrors RouteFetcherRegistry)', () => {
  const supported = [
    'https://www.komoot.com/tour/123',
    'https://www.komoot.com/fr-fr/tour/123',
    'https://www.komoot.com/collection/45',
    'https://www.komoot.com/de-de/collection/45',
    'https://www.strava.com/routes/999',
    'https://ridewithgps.com/routes/7',
  ];
  const unsupported = [
    '',
    '   ',
    'not a url',
    'http://www.komoot.com/tour/123', // http, not https
    'https://www.komoot.com/tour/abc', // non-numeric id
    'https://komoot.com/tour/123', // missing www
    'https://www.strava.com/activities/999', // wrong path
    'https://example.com/tour/123',
    'https://ridewithgps.com/trips/7', // wrong path
  ];

  it.each(supported)('accepts %s', (url) => {
    expect(isSupportedSourceUrl(url)).toBe(true);
  });

  it.each(unsupported)('rejects %s', (url) => {
    expect(isSupportedSourceUrl(url)).toBe(false);
  });

  it('trims surrounding whitespace before matching', () => {
    expect(isSupportedSourceUrl('  https://www.strava.com/routes/1  ')).toBe(true);
  });

  it('exposes four patterns (komoot tour/collection, strava, rwgps)', () => {
    expect(SUPPORTED_SOURCE_PATTERNS).toHaveLength(4);
  });
});

describe('runCreateTrip', () => {
  it('returns the new id on success and trims the URL', async () => {
    mockCreate.mockResolvedValue({ id: 'trip-1', status: 202 });
    const onFailure = jest.fn();

    const id = await runCreateTrip('  https://www.strava.com/routes/1  ', onFailure);

    expect(id).toBe('trip-1');
    expect(mockCreate).toHaveBeenCalledWith('https://www.strava.com/routes/1');
    expect(onFailure).not.toHaveBeenCalled();
  });

  it('reports "offline" and never calls the API when offline', async () => {
    useOfflineStore.setState({ isOnline: false });
    const onFailure = jest.fn();

    const id = await runCreateTrip('https://www.strava.com/routes/1', onFailure);

    expect(id).toBeNull();
    expect(mockCreate).not.toHaveBeenCalled();
    expect(onFailure).toHaveBeenCalledWith('offline');
  });

  it('normalizes a 422 rejection to "validation"', async () => {
    mockCreate.mockResolvedValue({ id: null, status: 422 });
    const onFailure = jest.fn();

    const id = await runCreateTrip('https://www.strava.com/routes/1', onFailure);

    expect(id).toBeNull();
    expect(onFailure).toHaveBeenCalledWith('validation');
  });

  it('reports "network" when the request throws', async () => {
    mockCreate.mockRejectedValue(new Error('boom'));
    const onFailure = jest.fn();

    const id = await runCreateTrip('https://www.strava.com/routes/1', onFailure);

    expect(id).toBeNull();
    expect(onFailure).toHaveBeenCalledWith('network');
  });
});

describe('pickGpxFile (#1043)', () => {
  it('returns the first picked asset (uri / name / mimeType)', async () => {
    mockPick.mockResolvedValue({
      canceled: false,
      assets: [
        { uri: 'file:///r.gpx', name: 'r.gpx', mimeType: 'application/gpx+xml', lastModified: 0 },
      ],
    } as never);

    expect(await pickGpxFile()).toEqual({
      uri: 'file:///r.gpx',
      name: 'r.gpx',
      mimeType: 'application/gpx+xml',
    });
  });

  it('returns null when the user cancels the picker', async () => {
    mockPick.mockResolvedValue({ canceled: true, assets: null } as never);
    expect(await pickGpxFile()).toBeNull();
  });

  it('resolves null (never rejects) when the picker throws', async () => {
    mockPick.mockRejectedValue(new Error('permission denied'));
    await expect(pickGpxFile()).resolves.toBeNull();
  });
});

describe('runUploadGpx (#1043)', () => {
  const file = { uri: 'file:///r.gpx', name: 'r.gpx', mimeType: 'application/gpx+xml' };

  it('returns the new id on success', async () => {
    mockUpload.mockResolvedValue({ id: 'gpx-1', status: 202 });
    const onFailure = jest.fn();

    const id = await runUploadGpx(file, onFailure);

    expect(id).toBe('gpx-1');
    expect(mockUpload).toHaveBeenCalledWith(file);
    expect(onFailure).not.toHaveBeenCalled();
  });

  it('reports "offline" and never uploads when offline', async () => {
    useOfflineStore.setState({ isOnline: false });
    const onFailure = jest.fn();

    const id = await runUploadGpx(file, onFailure);

    expect(id).toBeNull();
    expect(mockUpload).not.toHaveBeenCalled();
    expect(onFailure).toHaveBeenCalledWith('offline');
  });

  it('normalizes a 422 (track-less GPX) to "validation"', async () => {
    mockUpload.mockResolvedValue({ id: null, status: 422 });
    const onFailure = jest.fn();

    const id = await runUploadGpx(file, onFailure);

    expect(id).toBeNull();
    expect(onFailure).toHaveBeenCalledWith('validation');
  });

  it('normalizes a 400 (bad file) to "error"', async () => {
    mockUpload.mockResolvedValue({ id: null, status: 400 });
    const onFailure = jest.fn();

    await runUploadGpx(file, onFailure);

    expect(onFailure).toHaveBeenCalledWith('error');
  });

  it('reports "network" when the upload throws', async () => {
    mockUpload.mockRejectedValue(new Error('boom'));
    const onFailure = jest.fn();

    const id = await runUploadGpx(file, onFailure);

    expect(id).toBeNull();
    expect(onFailure).toHaveBeenCalledWith('network');
  });
});
