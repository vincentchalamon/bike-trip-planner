/// <reference types="jest" />
jest.mock('./client', () => ({
  api: { GET: jest.fn(), POST: jest.fn(), DELETE: jest.fn() },
}));
jest.mock('./config', () => ({ WEB_BASE_URL: 'https://web.example/' }));

import { api } from './client';
import {
  buildShareUrl,
  createTripShare,
  getTripShare,
  revokeTripShare,
} from './trips';

const mockGet = api.GET as jest.MockedFunction<typeof api.GET>;
const mockPost = api.POST as jest.MockedFunction<typeof api.POST>;
const mockDelete = api.DELETE as jest.MockedFunction<typeof api.DELETE>;

beforeEach(() => {
  jest.clearAllMocks();
});

describe('getTripShare (#1048)', () => {
  it('returns the active share on success', async () => {
    mockGet.mockResolvedValue({
      data: { shortCode: 'abc123', active: true },
      error: undefined,
    } as never);

    const res = await getTripShare('t1');

    expect(mockGet).toHaveBeenCalledWith('/trips/{tripId}/share', {
      params: { path: { tripId: 't1' } },
      headers: { Accept: 'application/ld+json' },
    });
    expect(res).toEqual({ shortCode: 'abc123', active: true });
  });

  it('returns null when there is no active share', async () => {
    mockGet.mockResolvedValue({ data: undefined, error: { detail: '404' } } as never);
    expect(await getTripShare('t1')).toBeNull();
  });
});

describe('createTripShare (#1048)', () => {
  it('POSTs an empty body and returns the created share', async () => {
    mockPost.mockResolvedValue({
      data: { shortCode: 'new999' },
      error: undefined,
    } as never);

    const res = await createTripShare('t1');

    expect(mockPost).toHaveBeenCalledWith('/trips/{tripId}/share', {
      params: { path: { tripId: 't1' } },
      headers: { Accept: 'application/ld+json', 'Content-Type': 'application/ld+json' },
      body: {},
    });
    expect(res).toEqual({ shortCode: 'new999' });
  });

  it('returns null on failure', async () => {
    mockPost.mockResolvedValue({ data: undefined, error: { detail: 'boom' } } as never);
    expect(await createTripShare('t1')).toBeNull();
  });
});

describe('revokeTripShare (#1048)', () => {
  it('returns true when the DELETE succeeds', async () => {
    mockDelete.mockResolvedValue({ response: { ok: true } } as never);
    expect(await revokeTripShare('t1')).toBe(true);
    expect(mockDelete).toHaveBeenCalledWith('/trips/{tripId}/share', {
      params: { path: { tripId: 't1' } },
      headers: { Accept: 'application/ld+json' },
    });
  });

  it('returns false when the DELETE fails', async () => {
    mockDelete.mockResolvedValue({ response: { ok: false } } as never);
    expect(await revokeTripShare('t1')).toBe(false);
  });
});

describe('buildShareUrl (#1048)', () => {
  it('builds the web /s/<code> URL from WEB_BASE_URL, stripping a trailing slash', () => {
    expect(buildShareUrl('abc123')).toBe('https://web.example/s/abc123');
  });

  it('encodes the short code', () => {
    expect(buildShareUrl('a b/c')).toBe('https://web.example/s/a%20b%2Fc');
  });
});
