/// <reference types="jest" />
import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import { registerDeviceToken, subscribeTokenRotation, unregisterDeviceToken } from './push';

jest.mock('expo-notifications', () => ({
  setNotificationHandler: jest.fn(),
  getPermissionsAsync: jest.fn(),
  requestPermissionsAsync: jest.fn(),
  getDevicePushTokenAsync: jest.fn(),
  addPushTokenListener: jest.fn(() => ({ remove: jest.fn() })),
}));

jest.mock('../auth/tokens', () => ({ getJwt: jest.fn(() => 'jwt') }));

const getPerms = Notifications.getPermissionsAsync as jest.Mock;
const reqPerms = Notifications.requestPermissionsAsync as jest.Mock;
const getToken = Notifications.getDevicePushTokenAsync as jest.Mock;

const expectedPlatform =
  Platform.OS === 'android' ? 'android' : Platform.OS === 'ios' ? 'ios' : null;

beforeEach(() => {
  jest.clearAllMocks();
  getPerms.mockResolvedValue({ granted: true });
  getToken.mockResolvedValue({ type: 'android', data: 'fcm-token-abc' });
  globalThis.fetch = jest.fn().mockResolvedValue(new Response(null, { status: 200 }));
});

describe('registerDeviceToken', () => {
  it('POSTs the native token + platform when permission is granted', async () => {
    await registerDeviceToken();
    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, init] = (fetch as jest.Mock).mock.calls[0];
    expect(url).toMatch(/\/users\/me\/device-tokens$/);
    expect(init).toMatchObject({ method: 'POST', headers: expect.objectContaining({ Authorization: 'Bearer jwt' }) });
    expect(JSON.parse(init.body)).toEqual({ token: 'fcm-token-abc', platform: expectedPlatform });
  });

  it('requests permission when not yet granted, then registers', async () => {
    getPerms.mockResolvedValue({ granted: false });
    reqPerms.mockResolvedValue({ granted: true });
    await registerDeviceToken();
    expect(reqPerms).toHaveBeenCalledTimes(1);
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('is a silent no-op when permission is denied', async () => {
    getPerms.mockResolvedValue({ granted: false });
    reqPerms.mockResolvedValue({ granted: false });
    await registerDeviceToken();
    expect(fetch).not.toHaveBeenCalled();
  });

  it('resolves without throwing and skips the POST when the permission API itself throws', async () => {
    getPerms.mockRejectedValue(new Error('no Google Play Services'));
    await expect(registerDeviceToken()).resolves.toBeUndefined();
    expect(fetch).not.toHaveBeenCalled();
  });

  it('resolves without throwing when the POST rejects (offline/timeout)', async () => {
    (fetch as jest.Mock).mockRejectedValue(new Error('network error'));
    await expect(registerDeviceToken()).resolves.toBeUndefined();
    expect(fetch).toHaveBeenCalledTimes(1);
  });
});

describe('unregisterDeviceToken', () => {
  it('DELETEs the token registered this session', async () => {
    await registerDeviceToken();
    (fetch as jest.Mock).mockClear();
    await unregisterDeviceToken();
    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, init] = (fetch as jest.Mock).mock.calls[0];
    expect(url).toMatch(/\/users\/me\/device-tokens\/fcm-token-abc$/);
    expect(init).toMatchObject({ method: 'DELETE', headers: expect.objectContaining({ Authorization: 'Bearer jwt' }) });
  });

  it('is a no-op when nothing was registered', async () => {
    // Fresh module state guaranteed by clearing the registered token first.
    await unregisterDeviceToken();
    await unregisterDeviceToken();
    expect(fetch).not.toHaveBeenCalled();
  });
});

describe('subscribeTokenRotation', () => {
  it('re-registers when the OS emits a rotated token', async () => {
    await registerDeviceToken();
    (fetch as jest.Mock).mockClear();
    subscribeTokenRotation();
    const listener = (Notifications.addPushTokenListener as jest.Mock).mock.calls[0][0];
    listener({ type: 'android', data: 'fcm-token-rotated' });
    // The listener re-registers fire-and-forget; flush the permission + token
    // fetch microtasks before asserting the POST landed.
    await new Promise((r) => setImmediate(r));
    await new Promise((r) => setImmediate(r));
    expect(fetch).toHaveBeenCalledTimes(1);
  });
});
