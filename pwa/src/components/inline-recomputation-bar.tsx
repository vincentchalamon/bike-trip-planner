"use client";

import { useTripStore } from "@/store/trip-store";

/**
 * Thin horizontal progress bar that appears at the very top of the page
 * during an Acte 3 inline recomputation. Visible only while at least one
 * stage is in the `recomputingStages` set; disappears automatically once
 * all pending `stage_updated` events have landed.
 *
 * The bar uses an indeterminate animation because the total number of
 * `stage_updated` events may not be known upfront (backend can batch them).
 */
export function InlineRecomputationBar() {
  const recomputingStages = useTripStore((s) => s.recomputingStages);

  if (recomputingStages.size === 0) return null;

  return (
    <div
      role="progressbar"
      aria-label="Recalcul en cours"
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuetext="Recalcul en cours…"
      data-testid="inline-recomputation-bar"
      className="fixed top-0 left-0 right-0 z-50 h-0.5 overflow-hidden"
    >
      <div className="h-full w-full bg-brand/20">
        <div className="h-full bg-brand animate-[indeterminate_1.5s_ease-in-out_infinite]" />
      </div>
    </div>
  );
}
