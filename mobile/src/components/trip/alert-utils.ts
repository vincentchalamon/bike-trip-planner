import type { AlertData } from '@btp/core';
import type { ColorScheme } from '../../theme';

// Severity buckets, rendered top-down (mirrors the web SEVERITY_ORDER).
export type AlertSeverity = AlertData['type'];
export const SEVERITY_ORDER: readonly AlertSeverity[] = [
  'critical',
  'warning',
  'nudge',
] as const;

/**
 * Stable identity of an alert, built on the backend `code` (`App\Enum\AlertCode`),
 * which names the rule variant and never changes when a message is reworded.
 * Dismissal and deduplication are keyed on this, never on the wording (see
 * CLAUDE.md "Alert engine"). Alerts persisted before the code existed carry
 * none: those fall back to the message (degraded but working, like the web).
 */
export function alertDedupKey(alert: AlertData): string {
  return alert.code ?? alert.message;
}

/**
 * Collapse alerts sharing one `code` to a single entry, keeping the first
 * occurrence and preserving order. Two variants of the same family (distinct
 * codes) stay apart; two wordings of the same code collapse.
 */
export function dedupeAlerts(alerts: AlertData[]): AlertData[] {
  const seen = new Set<string>();
  const out: AlertData[] = [];
  for (const alert of alerts) {
    const key = alertDedupKey(alert);
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(alert);
  }
  return out;
}

/**
 * Session-dismissal key: the stable `code` scoped to the stage it was dismissed
 * on. The web keeps dismissal in per-stage component state; the mobile store is
 * global, so the stage identity (the day number) must be part of the key — else
 * dismissing an alert on one day hides the same `code` on every other day
 * (#1038 review). Dedup stays intra-stage on `alertDedupKey`; only the
 * dismissal is scoped per stage.
 */
export function alertDismissKey(
  stageKey: string | number,
  alert: AlertData,
): string {
  return `${stageKey}:${alertDedupKey(alert)}`;
}

/**
 * Deduplicated alerts (intra-stage, by `code`) minus the ones dismissed on this
 * stage. Dedup keys on `alertDedupKey`; dismissal keys on `alertDismissKey`, so
 * a dismissed alert stays hidden across SSE updates that reword or re-emit it,
 * without leaking the dismissal to other stages sharing the same `code`.
 */
export function visibleAlerts(
  alerts: AlertData[],
  dismissed: ReadonlySet<string>,
  stageKey: string | number,
): AlertData[] {
  return dedupeAlerts(alerts).filter(
    (a) => !dismissed.has(alertDismissKey(stageKey, a)),
  );
}

/** Group alerts by severity, preserving arrival order within each bucket. */
export function groupBySeverity(
  alerts: AlertData[],
): Record<AlertSeverity, AlertData[]> {
  const groups: Record<AlertSeverity, AlertData[]> = {
    critical: [],
    warning: [],
    nudge: [],
  };
  for (const alert of alerts) groups[alert.type].push(alert);
  return groups;
}

interface SeverityStyle {
  bg: string;
  fg: string;
}

// Severity palette — RN mirror of the web AlertBadge tints (red / orange /
// blue), light and dark. Kept local to the alert blocks: these are not part of
// the shared theme tokens.
const SEVERITY_PALETTE: Record<ColorScheme, Record<AlertSeverity, SeverityStyle>> = {
  light: {
    critical: { bg: '#fee2e2', fg: '#9a1616' },
    warning: { bg: '#ffedd5', fg: '#9a3412' },
    nudge: { bg: '#dbeafe', fg: '#1e40af' },
  },
  dark: {
    critical: { bg: 'rgba(127,29,29,0.35)', fg: '#f7a6a6' },
    warning: { bg: 'rgba(124,45,18,0.35)', fg: '#fdba74' },
    nudge: { bg: 'rgba(30,58,138,0.35)', fg: '#93c5fd' },
  },
};

export function severityStyle(
  type: AlertSeverity,
  scheme: ColorScheme,
): SeverityStyle {
  return SEVERITY_PALETTE[scheme][type];
}
