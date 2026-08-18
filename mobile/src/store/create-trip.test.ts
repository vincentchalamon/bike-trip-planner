/// <reference types="jest" />
import {
  isSupportedSourceUrl,
  runCreateTrip,
  SUPPORTED_SOURCE_PATTERNS,
} from './create-trip';
import { useOfflineStore } from './offline-store';

jest.mock('../api/trips', () => ({ createTrip: jest.fn() }));
import { createTrip } from '../api/trips';
const mockCreate = createTrip as jest.MockedFunction<typeof createTrip>;

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
