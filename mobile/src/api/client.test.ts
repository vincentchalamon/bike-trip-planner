/// <reference types="jest" />
import { authMiddleware } from './client';

jest.mock('../auth/tokens', () => ({ getJwt: jest.fn() }));
jest.mock('../auth/authApi', () => ({ refreshTokens: jest.fn() }));
import { getJwt } from '../auth/tokens';
import { refreshTokens } from '../auth/authApi';
import i18n from '../i18n';

const mockGetJwt = getJwt as jest.MockedFunction<typeof getJwt>;
const mockRefresh = refreshTokens as jest.MockedFunction<typeof refreshTokens>;

// The middleware callback params carry many fields openapi-fetch fills in; only
// `request`/`response` matter here, so cast the partial shapes.
const onRequest = (request: Request) => (authMiddleware.onRequest as never as (p: { request: Request }) => unknown)({ request });
const onResponse = (request: Request, response: Response) =>
  (authMiddleware.onResponse as never as (p: { request: Request; response: Response }) => Promise<Response | undefined>)({
    request,
    response,
  });

beforeEach(() => {
  jest.clearAllMocks();
  globalThis.fetch = jest.fn();
});

describe('authMiddleware request headers', () => {
  // Regression (#1090 device): RN advertises `zstd, br, gzip`; the server then
  // picks zstd/br which okhttp does not transparently decode, mojibaking accented
  // titles. The edge (Caddy) keys its compression opt-out on this header, so it
  // must be sent on every request — must not be refactored away.
  it('tags every request with X-Client-Platform: mobile', async () => {
    mockGetJwt.mockReturnValue('jwt');
    const request = new Request('https://api.test/trips');
    await onRequest(request);
    expect(request.headers.get('X-Client-Platform')).toBe('mobile');
  });

  // Regression (#1169): RN's fetch never sends Accept-Language on its own, so a
  // trip created from the app was always analyzed server-side in the backend's
  // default locale (en) regardless of the app's own French UI.
  it('carries the app locale as Accept-Language so backend-rendered alert text matches the UI language', async () => {
    mockGetJwt.mockReturnValue('jwt');
    await i18n.changeLanguage('fr');
    const request = new Request('https://api.test/trips');
    await onRequest(request);
    expect(request.headers.get('Accept-Language')).toBe('fr');
    await i18n.changeLanguage('en');
    const englishRequest = new Request('https://api.test/trips');
    await onRequest(englishRequest);
    expect(englishRequest.headers.get('Accept-Language')).toBe('en');
    await i18n.changeLanguage('fr');
  });
});

describe('authMiddleware 401 retry (#1032)', () => {
  it('refreshes the token and replays the request with the new JWT on a 401', async () => {
    mockGetJwt.mockReturnValueOnce('stale-jwt').mockReturnValue('fresh-jwt');
    mockRefresh.mockResolvedValue(true);
    (globalThis.fetch as jest.Mock).mockResolvedValue(
      new Response('{"ok":true}', { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    const request = new Request('https://api.test/trips/1');
    await onRequest(request);
    expect(request.headers.get('Authorization')).toBe('Bearer stale-jwt');

    const result = await onResponse(request, new Response(null, { status: 401 }));

    expect(mockRefresh).toHaveBeenCalledTimes(1);
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    const retried = (globalThis.fetch as jest.Mock).mock.calls[0][0] as Request;
    expect(retried.headers.get('Authorization')).toBe('Bearer fresh-jwt');
    expect(retried.headers.get('X-Native-Retry')).toBe('1');
    expect(result).toBeInstanceOf(Response);
    expect(result?.status).toBe(200);
  });

  // Regression (#1047 review): a FIT export retried after a 401 must come back
  // byte-for-byte — a `.text()` round-trip re-encodes as UTF-8 and corrupts
  // binary content.
  it('preserves a binary body byte-for-byte across the retry (#1047)', async () => {
    mockGetJwt.mockReturnValueOnce('stale-jwt').mockReturnValue('fresh-jwt');
    mockRefresh.mockResolvedValue(true);
    const bytes = new Uint8Array([0x0e, 0x10, 0xff, 0x00, 0x8a, 0x2e]);
    (globalThis.fetch as jest.Mock).mockResolvedValue(
      new Response(bytes, {
        status: 200,
        headers: { 'Content-Type': 'application/vnd.ant.fit' },
      }),
    );

    const request = new Request('https://api.test/trips/1/stages/1/export');
    await onRequest(request);
    const result = await onResponse(request, new Response(null, { status: 401 }));

    expect(result).toBeInstanceOf(Response);
    const resultBytes = new Uint8Array(await result!.arrayBuffer());
    expect(resultBytes).toEqual(bytes);
  });

  it('does not replay when the refresh fails', async () => {
    mockGetJwt.mockReturnValue('stale-jwt');
    mockRefresh.mockResolvedValue(false);

    const request = new Request('https://api.test/trips/1');
    await onRequest(request);
    const result = await onResponse(request, new Response(null, { status: 401 }));

    expect(mockRefresh).toHaveBeenCalledTimes(1);
    expect(globalThis.fetch).not.toHaveBeenCalled();
    expect(result).toBeUndefined();
  });

  it('never refreshes on an already-retried request (no loop)', async () => {
    mockGetJwt.mockReturnValue('stale-jwt');

    const request = new Request('https://api.test/trips/1', { headers: { 'X-Native-Retry': '1' } });
    await onRequest(request);
    const result = await onResponse(request, new Response(null, { status: 401 }));

    expect(mockRefresh).not.toHaveBeenCalled();
    expect(result).toBeUndefined();
  });

  it('never refreshes on an auth-endpoint 401', async () => {
    mockGetJwt.mockReturnValue('stale-jwt');

    const request = new Request('https://api.test/auth/verify');
    await onRequest(request);
    const result = await onResponse(request, new Response(null, { status: 401 }));

    expect(mockRefresh).not.toHaveBeenCalled();
    expect(result).toBeUndefined();
  });

  it('leaves a non-401 response unchanged', async () => {
    mockGetJwt.mockReturnValue('jwt');

    const request = new Request('https://api.test/trips/1');
    await onRequest(request);
    const result = await onResponse(request, new Response('{}', { status: 200 }));

    expect(mockRefresh).not.toHaveBeenCalled();
    expect(result).toBeUndefined();
  });
});
