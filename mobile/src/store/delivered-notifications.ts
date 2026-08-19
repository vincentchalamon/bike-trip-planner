import { create } from 'zustand';
import * as SecureStore from 'expo-secure-store';

// Persistent set of local-notification identifiers that have already been
// delivered. A one-shot DATE trigger is dropped from
// getAllScheduledNotificationsAsync() the moment it fires, so the absence of an id
// from the scheduled set cannot tell "never scheduled" from "already delivered".
// This set records the latter and survives restarts (else reopening the app would
// re-fire every past-due reminder). Rides on SecureStore like the other prefs.
const KEY = 'btp_delivered_notifications';

interface DeliveredNotificationsState {
  delivered: Set<string>;
  hydrated: boolean;
  // Record an id as delivered (a past-due one-shot that has just fired).
  markDelivered: (id: string) => void;
  // Forget an id so it can fire again (its underlying condition reset, e.g. a
  // departure pushed back into the future re-arms offlineNotReady).
  clearDelivered: (id: string) => void;
  load: () => Promise<void>;
}

function persist(delivered: Set<string>): void {
  void SecureStore.setItemAsync(KEY, JSON.stringify([...delivered]));
}

function parseStored(raw: string | null): Set<string> {
  if (!raw) return new Set<string>();
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (!Array.isArray(parsed)) return new Set<string>();
    return new Set(parsed.filter((x): x is string => typeof x === 'string'));
  } catch {
    return new Set<string>();
  }
}

export const useDeliveredNotifications = create<DeliveredNotificationsState>((set, get) => ({
  delivered: new Set<string>(),
  hydrated: false,
  markDelivered: (id) =>
    set((s) => {
      if (s.delivered.has(id)) return {};
      const delivered = new Set(s.delivered);
      delivered.add(id);
      persist(delivered);
      return { delivered };
    }),
  clearDelivered: (id) =>
    set((s) => {
      if (!s.delivered.has(id)) return {};
      const delivered = new Set(s.delivered);
      delivered.delete(id);
      persist(delivered);
      return { delivered };
    }),
  load: async () => {
    if (get().hydrated) return;
    const stored = await SecureStore.getItemAsync(KEY);
    set({ delivered: parseStored(stored), hydrated: true });
  },
}));
