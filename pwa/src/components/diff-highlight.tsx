"use client";

import { useTripStore } from "@/store/trip-store";

interface DiffHighlightProps {
  /** Index of the stage this highlight belongs to. */
  stageIndex: number;
  /**
   * Logical field name to watch. When this field appears in the stage's
   * `stageDiffs` set, the children receive a transient highlight animation.
   *
   * Valid values: `"distance"`, `"elevation"`, `"arrivalTime"`,
   * `"alerts_added"`, `"alerts_removed"`, `"selectedAccommodation"`.
   */
  field: string;
  /** Content to wrap. Receives the highlight class when the field changed. */
  children: React.ReactNode;
  /**
   * Optional accessible label suffix appended to a visually-hidden span so
   * screen-reader users are informed of the change without relying on colour
   * alone. Defaults to the empty string (no announcement).
   */
  changeLabel?: string;
}

/**
 * Wraps its children in a `<span>` that temporarily receives a yellow-to-
 * transparent background animation whenever the specified `field` appears in
 * the `stageDiffs` map for the given `stageIndex`.
 *
 * The highlight is self-resetting: the store entry is cleared after ~3 s by
 * the timer set in `use-mercure.ts`, which automatically removes the CSS class
 * because the component re-renders when the store changes.
 *
 * Accessibility: a visually-hidden `<span aria-live="polite">` announces the
 * change to assistive technologies when `changeLabel` is provided, satisfying
 * WCAG 1.4.1 (not solely colour-based).
 *
 * Usage:
 * ```tsx
 * <DiffHighlight stageIndex={i} field="distance" changeLabel="Distance modifiée">
 *   <span>{stage.distance} km</span>
 * </DiffHighlight>
 * ```
 */
export function DiffHighlight({
  stageIndex,
  field,
  children,
  changeLabel = "",
}: DiffHighlightProps) {
  const isChanged = useTripStore(
    (s) => s.stageDiffs.get(stageIndex)?.has(field) ?? false,
  );

  return (
    <span
      className={isChanged ? "diff-highlight rounded px-0.5" : undefined}
      data-testid={isChanged ? `diff-highlight-${field}` : undefined}
    >
      {children}
      {isChanged && changeLabel && (
        <span
          className="sr-only"
          aria-live="polite"
          aria-atomic="true"
        >
          {changeLabel}
        </span>
      )}
    </span>
  );
}
