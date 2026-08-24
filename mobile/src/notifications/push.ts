import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';
import { API_BASE_URL, LD_JSON } from '../api/config';
import { getJwt } from '../auth/tokens';

// Server-driven push (#1125): register this device's native FCM (Android) / APNs
// (iOS) token with the backend so it can target the user, and route an incoming
// notification to the right screen. Distinct from on-device local triggers
// (#1121, `notifications/local.*` if present) — this file only owns the push leg.
//
// Uses plain fetch rather than the typed API client (../api/client): that module
// imports authApi.ts for its 401-retry middleware, and authApi.ts imports this
// file's unregisterDeviceToken for its own definitive-failure cleanup (#1125) —
// routing through the typed client here would close the three into a require
// cycle (#1173). Both calls below run right after a login/rotation or with a
// still-valid JWT just before logout, so the typed client's refresh-and-retry
// middleware has nothing to add.

type DevicePlatform = 'android' | 'ios' | null;

// Token registered this session, kept so logout can DELETE it without re-querying
// the OS (permission may already be gone). Also gates the rotation listener.
let registeredToken: string | null = null;

// Foreground presentation: the OS suppresses banners while the app is in the
// foreground unless a handler opts in. Service pushes (weather, analysis) are
// worth showing; badges stay off (no unread-count model). Called once at app
// start (usePushRouting) rather than at import, so pulling this module in for
// register/unregister carries no side effect (keeps the auth store unit-testable).
export function configurePushPresentation(): void {
  Notifications.setNotificationHandler({
    handleNotification: async () => ({
      shouldShowBanner: true,
      shouldShowList: true,
      shouldPlaySound: true,
      shouldSetBadge: false,
    }),
  });
}

function devicePlatform(): DevicePlatform {
  if (Platform.OS === 'android') return 'android';
  if (Platform.OS === 'ios') return 'ios';
  return null;
}

// Obtain the native device token the backend `DeviceToken` entity stores. Returns
// null when permission is absent or the OS cannot mint one (e.g. a simulator
// without push support): registration is then a silent no-op.
async function fetchDeviceToken(): Promise<string | null> {
  try {
    const current = await Notifications.getPermissionsAsync();
    const granted = current.granted || (await Notifications.requestPermissionsAsync()).granted;
    if (!granted) return null;
    const { data } = await Notifications.getDevicePushTokenAsync();
    return typeof data === 'string' ? data : null;
  } catch {
    // The permission API itself throws on some builds (Android without Google
    // Play Services, custom ROMs, emulators), not just getDevicePushTokenAsync.
    // register is fired fire-and-forget from the auth store, so an unhandled
    // rejection would crash it — keep the whole path a silent no-op.
    return null;
  }
}

function authHeader(): Record<string, string> {
  const jwt = getJwt();
  return jwt ? { Authorization: `Bearer ${jwt}` } : {};
}

// Idempotent upsert of this device's token for the current user. Safe to call on
// every login and on rotation: the backend dedupes by token (schema comment) and
// reassigns a token held by another account.
export async function registerDeviceToken(): Promise<void> {
  const token = await fetchDeviceToken();
  if (!token) return;
  registeredToken = token;
  try {
    await fetch(`${API_BASE_URL}/users/me/device-tokens`, {
      method: 'POST',
      headers: { 'Content-Type': LD_JSON, Accept: LD_JSON, ...authHeader() },
      body: JSON.stringify({ token, platform: devicePlatform() }),
    });
  } catch {
    // Best-effort: register is fired fire-and-forget from the auth store, so a
    // network reject (offline/timeout) must not crash it. Registration retries
    // on the next login/rotation. Symmetric to unregisterDeviceToken.
  }
}

// Remove this device's token on logout so a shared device stops receiving the
// previous user's pushes. Must run while the JWT is still valid (before
// clearTokens). No-op when nothing was registered this session.
export async function unregisterDeviceToken(): Promise<void> {
  const token = registeredToken;
  if (!token) return;
  registeredToken = null;
  await fetch(`${API_BASE_URL}/users/me/device-tokens/${encodeURIComponent(token)}`, {
    method: 'DELETE',
    headers: { Accept: LD_JSON, ...authHeader() },
  });
}

// FCM/APNs rotate tokens out of band; re-register when the OS emits a new one so
// the backend never targets a dead token. Only acts once a session is registered.
export function subscribeTokenRotation(): { remove: () => void } {
  return Notifications.addPushTokenListener(() => {
    if (registeredToken) void registerDeviceToken();
  });
}
