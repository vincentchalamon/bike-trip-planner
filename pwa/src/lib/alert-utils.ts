import type { AlertData } from "@/lib/validation/schemas";

export const severityOrder = { critical: 0, warning: 1, nudge: 2 } as const;

export function sortBySeverity(alerts: AlertData[]): AlertData[] {
  return [...alerts].sort(
    (a, b) => (severityOrder[a.type] ?? 2) - (severityOrder[b.type] ?? 2),
  );
}
