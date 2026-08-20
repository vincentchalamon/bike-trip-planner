/// <reference types="jest" />
jest.mock('../api/client', () => ({ api: { POST: jest.fn() } }));
import TestRenderer, { act } from 'react-test-renderer';
import { createElement } from 'react';
import { api } from '../api/client';
import {
  requestEmailChange,
  verifyEmailChange,
  useEmailChange,
  type UseEmailChange,
} from './use-email-change';

const mockPost = api.POST as jest.MockedFunction<typeof api.POST>;

beforeEach(() => jest.clearAllMocks());

function deferred<T>(): { promise: Promise<T>; resolve: (v: T) => void } {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

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

describe('useEmailChange state machine (#1117)', () => {
  let captured: UseEmailChange;
  let renderer: ReturnType<typeof TestRenderer.create>;

  function Harness() {
    captured = useEmailChange();
    return null;
  }

  function render() {
    act(() => {
      renderer = TestRenderer.create(createElement(Harness));
    });
  }

  afterEach(() => act(() => renderer?.unmount()));

  it('toggles busy while in flight, then flips sent on success', async () => {
    const d = deferred<{ response: { ok: boolean } }>();
    mockPost.mockReturnValue(d.promise as never);
    render();

    expect(captured.busy).toBe(false);
    expect(captured.sent).toBe(false);

    act(() => {
      void captured.submit('new@example.com');
    });
    expect(captured.busy).toBe(true);

    await act(async () => {
      d.resolve({ response: { ok: true } });
      await d.promise;
    });

    expect(captured.busy).toBe(false);
    expect(captured.sent).toBe(true);
    expect(captured.error).toBe(false);
  });

  it('sets error (not sent) when the request is rejected', async () => {
    mockPost.mockResolvedValue({ response: { ok: false } } as never);
    render();

    await act(async () => {
      await captured.submit('bad');
    });

    expect(captured.error).toBe(true);
    expect(captured.sent).toBe(false);
    expect(captured.busy).toBe(false);
  });

  it('clears the error via reset (e.g. on the next keystroke)', async () => {
    mockPost.mockResolvedValue({ response: { ok: false } } as never);
    render();

    await act(async () => {
      await captured.submit('bad');
    });
    expect(captured.error).toBe(true);

    act(() => captured.reset());
    expect(captured.error).toBe(false);
  });
});
