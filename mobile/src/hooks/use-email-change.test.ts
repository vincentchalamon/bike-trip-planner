/// <reference types="jest" />
jest.mock('../api/client', () => ({ api: { POST: jest.fn() } }));
import { api } from '../api/client';
import { requestEmailChange, verifyEmailChange } from './use-email-change';

const mockPost = api.POST as jest.MockedFunction<typeof api.POST>;

beforeEach(() => jest.clearAllMocks());

describe('requestEmailChange (#1117)', () => {
  it('POSTs the new address to /users/me/email-change and returns true on 202', async () => {
    mockPost.mockResolvedValue({ response: { ok: true } } as never);

    const ok = await requestEmailChange('new@example.com');

    expect(ok).toBe(true);
    expect(mockPost).toHaveBeenCalledWith('/users/me/email-change', {
      body: { newEmail: 'new@example.com' },
      headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
    });
  });

  it('returns false when the request is rejected', async () => {
    mockPost.mockResolvedValue({ response: { ok: false } } as never);
    expect(await requestEmailChange('bad')).toBe(false);
  });
});

describe('verifyEmailChange (#1117)', () => {
  it('POSTs the token to /users/me/email-change/verify and returns true on 204', async () => {
    mockPost.mockResolvedValue({ response: { ok: true } } as never);

    const ok = await verifyEmailChange('tok-123');

    expect(ok).toBe(true);
    expect(mockPost).toHaveBeenCalledWith('/users/me/email-change/verify', {
      body: { newEmail: '', token: 'tok-123' },
      headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
    });
  });
});
