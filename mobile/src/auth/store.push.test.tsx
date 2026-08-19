/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { AuthProvider, useAuth } from './store';
import { registerDeviceToken, unregisterDeviceToken } from '../notifications/push';
import { verifyMagicToken } from './authApi';

// Wiring test (#1125): the AuthProvider must register the push token on login and
// unregister it on logout. The push module itself is stubbed — its POST/DELETE
// behaviour is covered by push.test.ts.
jest.mock('../notifications/push', () => ({
  registerDeviceToken: jest.fn().mockResolvedValue(undefined),
  unregisterDeviceToken: jest.fn().mockResolvedValue(undefined),
  subscribeTokenRotation: jest.fn(() => ({ remove: jest.fn() })),
}));

jest.mock('./authApi', () => ({ verifyMagicToken: jest.fn() }));
jest.mock('./tokens', () => ({
  loadTokens: jest.fn().mockResolvedValue({ jwt: null, refresh: null }),
  clearTokens: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('./session', () => ({ onSessionInvalidated: jest.fn(() => () => undefined) }));
jest.mock('../api/client', () => ({
  api: { GET: jest.fn().mockResolvedValue({ data: { email: 'a@b.co' } }) },
}));

const register = registerDeviceToken as jest.Mock;
const unregister = unregisterDeviceToken as jest.Mock;
const verify = verifyMagicToken as jest.Mock;

let auth: ReturnType<typeof useAuth>;
function Capture() {
  auth = useAuth();
  return null;
}

async function mount() {
  await act(async () => {
    TestRenderer.create(
      <AuthProvider>
        <Capture />
      </AuthProvider>,
    );
    await Promise.resolve();
  });
}

beforeEach(() => jest.clearAllMocks());

describe('AuthProvider push wiring', () => {
  it('registers the device token on login', async () => {
    verify.mockResolvedValue(true);
    await mount();
    expect(register).not.toHaveBeenCalled();

    await act(async () => {
      await auth.verify('magic-token');
    });

    expect(register).toHaveBeenCalledTimes(1);
  });

  it('unregisters the device token on logout', async () => {
    verify.mockResolvedValue(true);
    await mount();
    await act(async () => {
      await auth.verify('magic-token');
    });

    await act(async () => {
      await auth.logout();
    });

    expect(unregister).toHaveBeenCalledTimes(1);
  });
});
