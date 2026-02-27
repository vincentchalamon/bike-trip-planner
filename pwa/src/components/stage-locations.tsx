"use client";

import { useTranslations } from "next-intl";
import { ArrowRight } from "lucide-react";

interface StageLocationsProps {
  stageIndex: number;
  startLabel: string;
  endLabel: string;
}

export function StageLocations({
  stageIndex,
  startLabel,
  endLabel,
}: StageLocationsProps) {
  const t = useTranslations("stageLocations");

  return (
    <div className="flex items-center gap-2 flex-wrap">
      <span
        className="font-semibold text-sm"
        aria-label={t("departure", { n: stageIndex + 1 })}
        data-testid={`stage-${stageIndex + 1}-departure`}
      >
        {startLabel}
      </span>

      <ArrowRight className="h-4 w-4 text-muted-icon shrink-0" />

      <span
        className="font-semibold text-sm"
        aria-label={t("arrival", { n: stageIndex + 1 })}
        data-testid={`stage-${stageIndex + 1}-arrival`}
      >
        {endLabel}
      </span>
    </div>
  );
}
