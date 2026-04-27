"use client";

import { useTranslations } from "next-intl";
import { useTripStore } from "@/store/trip-store";

export function InlineRecomputationBar() {
  const recomputingStages = useTripStore((s) => s.recomputingStages);
  const t = useTranslations("stage");

  if (recomputingStages.size === 0) return null;

  return (
    <div
      role="progressbar"
      aria-label={t("recomputing")}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuetext={t("recomputing")}
      data-testid="inline-recomputation-bar"
      className="fixed top-0 left-0 right-0 z-50 h-0.5 overflow-hidden"
    >
      <div className="h-full w-full bg-brand/20">
        <div className="h-full bg-brand animate-[indeterminate_1.5s_ease-in-out_infinite]" />
      </div>
    </div>
  );
}
