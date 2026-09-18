/// <reference types="jest" />
const mockDelete = jest.fn();
const mockPatch = jest.fn();

jest.mock('./client', () => ({
  api: {
    DELETE: (...args: unknown[]) => mockDelete(...args),
    PATCH: (...args: unknown[]) => mockPatch(...args),
  },
}));

import { deleteAccount, updateAccountLocale } from './account';

beforeEach(() => {
  jest.clearAllMocks();
});

describe('deleteAccount', () => {
  it('resolves true on a 204 response', async () => {
    mockDelete.mockResolvedValue({ response: { ok: true, status: 204 } });
    expect(await deleteAccount()).toBe(true);
  });

  it('resolves false on a non-ok response', async () => {
    mockDelete.mockResolvedValue({ response: { ok: false, status: 403 } });
    expect(await deleteAccount()).toBe(false);
  });

  it('resolves false (never throws) when the request rejects (offline/timeout)', async () => {
    mockDelete.mockRejectedValue(new Error('Network request failed'));
    await expect(deleteAccount()).resolves.toBe(false);
  });
});

describe('updateAccountLocale', () => {
  it('sends the locale as a JSON merge patch on /users/me', async () => {
    mockPatch.mockResolvedValue({ response: { ok: true, status: 200 } });

    expect(await updateAccountLocale('en')).toBe(true);

    expect(mockPatch).toHaveBeenCalledWith('/users/me', {
      headers: expect.objectContaining({ 'Content-Type': 'application/merge-patch+json' }),
      body: { locale: 'en' },
    });
  });

  it('resolves false (never throws) when the request rejects, so the language still switches', async () => {
    // The UI language change is local and immediate; it must not be held hostage
    // to the network. A failed sync leaves the account on its previous locale.
    mockPatch.mockRejectedValue(new Error('Network request failed'));
    await expect(updateAccountLocale('fr')).resolves.toBe(false);
  });
});
