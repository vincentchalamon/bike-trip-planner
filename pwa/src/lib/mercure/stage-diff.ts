import type { StageData } from "@btp/core";

/**
 * The logical field names that changed between two snapshots of one stage.
 *
 * Feeds `stageDiffs` in the store, which `DiffHighlight` reads to flash each changed piece of
 * data for a few seconds after a `stage_updated`.
 *
 * Pure, and kept that way: it is the only part of the `stage_updated` reaction that can be
 * tested without a store, a timer or a browser.
 *
 * Compared fields: `distance`, `alerts_added`.
 */
export function computeStageDiff(
  previous: StageData,
  next: StageData,
): Set<string> {
  const changed = new Set<string>();

  if (previous.distance !== next.distance) changed.add("distance");

  // Alerts: only ADDITIONS are highlighted. An alert that disappeared is good news and needs no
  // attention drawn to it, and the identity used here — type + message — is what the user reads,
  // not the stable `code` dismissal keys on (ADR-069).
  const before = new Set(
    previous.alerts.map((alert) => `${alert.type}:${alert.message}`),
  );
  const added = next.alerts.some(
    (alert) => !before.has(`${alert.type}:${alert.message}`),
  );
  if (added) changed.add("alerts_added");

  return changed;
}
