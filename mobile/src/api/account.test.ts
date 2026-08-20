/// <reference types="jest" />
const mockDelete = jest.fn();

jest.mock('./client', () => ({ api: { DELETE: (...args: unknown[]) => mockDelete(...args) } }));

import { deleteAccount } from './account';

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
