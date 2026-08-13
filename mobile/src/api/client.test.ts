/// <reference types="jest" />
import { authMiddleware } from './client';

jest.mock('../auth/tokens', () => ({ getJwt: jest.fn() }));
jest.mock('../auth/authApi', () => ({ refreshTokens: jest.fn() }));
import { getJwt } from '../auth/tokens';
import { refreshTokens } from '../auth/authApi';

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
