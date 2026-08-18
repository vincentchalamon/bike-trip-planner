// Pure wiring helpers for the roadbook screen, extracted so the confirm/mutate
// logic is unit-testable rather than inlined in the component (mirrors
// confirmDeleteTrip in use-trips.ts and confirmExportFormat in use-export.ts).

/**
 * The trimmed title to persist after an inline edit, or null when there is
 * nothing to save (empty, whitespace-only, or unchanged from the current title).
 */
export function nextTitle(draft: string, current: string | null): string | null {
  const next = draft.trim();
  if (!next || next === (current ?? '')) return null;
  return next;
}
