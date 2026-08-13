import { create } from 'zustand';

// Session-only alert dismissal, keyed on the stable AlertCode (see
// alert-utils.alertDedupKey) — never on the wording. Kept out of trip-store so
// reconciling stages does not reset it, and vice versa. In-memory by design:
// dismissals do not survive an app restart (mirrors the web's session state).
interface DismissedAlertsState {
  dismissed: Set<string>;
  dismiss: (key: string) => void;
  restore: (key: string) => void;
  isDismissed: (key: string) => boolean;
  reset: () => void;
}

export const useDismissedAlerts = create<DismissedAlertsState>((set, get) => ({
  dismissed: new Set<string>(),
  dismiss: (key) =>
    set((s) => {
      if (s.dismissed.has(key)) return {};
      const next = new Set(s.dismissed);
      next.add(key);
      return { dismissed: next };
    }),
  restore: (key) =>
    set((s) => {
      if (!s.dismissed.has(key)) return {};
      const next = new Set(s.dismissed);
      next.delete(key);
      return { dismissed: next };
    }),
  isDismissed: (key) => get().dismissed.has(key),
  reset: () => set({ dismissed: new Set<string>() }),
}));
