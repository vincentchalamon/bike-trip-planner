import { create } from 'zustand';
import * as SecureStore from 'expo-secure-store';

// The five notification categories. Ordering is presentation order (the screen
// renders them in this sequence); the enum values are the stable persistence
// keys read back by #1121 (local triggers) and #1125 (server push).
export const NOTIFICATION_CATEGORIES = [
  'weatherSafety',
  'analysisDone',
  'offlineNotReady',
  'tripNoDate',
  'zoneOpening',
] as const;

export type NotificationCategory = (typeof NOTIFICATION_CATEGORIES)[number];

// Delivery channel per category: `push` is server-driven (registered by #1125),
// `local` is scheduled on-device (by #1121). This store only records the user's
// on/off choice; consumers read the channel to decide how to honour it.
export type NotificationChannel = 'push' | 'local';

export const NOTIFICATION_CHANNELS: Record<NotificationCategory, NotificationChannel> = {
  weatherSafety: 'push',
  analysisDone: 'push',
  offlineNotReady: 'local',
  tripNoDate: 'local',
  zoneOpening: 'push',
};

// Default on/off per category. Everything is on except `zoneOpening`, which is
// opt-in (a broadcast announcement, off by default).
export const NOTIFICATION_DEFAULTS: Record<NotificationCategory, boolean> = {
  weatherSafety: true,
  analysisDone: true,
  offlineNotReady: true,
  tripNoDate: true,
  zoneOpening: false,
};

// SecureStore key (alnum/._- only). Rides on SecureStore like the base-map and
// theme choices rather than pulling in AsyncStorage.
const KEY = 'btp_notification_prefs';

interface NotificationPrefsState {
  enabled: Record<NotificationCategory, boolean>;
  hydrated: boolean;
  setEnabled: (category: NotificationCategory, value: boolean) => void;
  toggle: (category: NotificationCategory) => void;
  load: () => Promise<void>;
}

// Merge persisted values over the defaults so an unknown/malformed payload falls
// back to defaults and any category absent from storage keeps its default.
function mergeStored(raw: string | null): Record<NotificationCategory, boolean> {
  const enabled = { ...NOTIFICATION_DEFAULTS };
  if (!raw) return enabled;
  try {
    const parsed = JSON.parse(raw) as Partial<Record<NotificationCategory, unknown>>;
    for (const category of NOTIFICATION_CATEGORIES) {
      if (typeof parsed[category] === 'boolean') enabled[category] = parsed[category];
    }
  } catch {
    return { ...NOTIFICATION_DEFAULTS };
  }
  return enabled;
}

// Persisted notification-category preferences. Writes are fire-and-forget so the
// setters stay synchronous; `load()` hydrates once at screen mount.
export const useNotificationPrefs = create<NotificationPrefsState>((set, get) => ({
  enabled: { ...NOTIFICATION_DEFAULTS },
  hydrated: false,
  setEnabled: (category, value) => {
    const enabled = { ...get().enabled, [category]: value };
    set({ enabled });
    void SecureStore.setItemAsync(KEY, JSON.stringify(enabled));
  },
  toggle: (category) => get().setEnabled(category, !get().enabled[category]),
  load: async () => {
    if (get().hydrated) return;
    const stored = await SecureStore.getItemAsync(KEY);
    set({ enabled: mergeStored(stored), hydrated: true });
  },
}));

// Number of categories currently enabled — drives the Account row counter.
export const selectActiveCount = (state: NotificationPrefsState): number =>
  NOTIFICATION_CATEGORIES.reduce((count, category) => count + (state.enabled[category] ? 1 : 0), 0);
