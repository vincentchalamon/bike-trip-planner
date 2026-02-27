"use client";

import { useTranslations } from "next-intl";
import { HelpCircle } from "lucide-react";
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";

interface PacingSettingsProps {
  fatigueFactor: number;
  elevationPenalty: number;
  onUpdate: (fatigueFactor: number, elevationPenalty: number) => void;
}

function toFatiguePercent(factor: number): number {
  return Math.round((1 - factor) * 100);
}

function fromFatiguePercent(percent: number): number {
  return 1 - percent / 100;
}

function toElevationPercent(penalty: number): number {
  return Math.round(penalty / 5);
}

function fromElevationPercent(percent: number): number {
  return percent * 5;
}

export function PacingSettings({
  fatigueFactor,
  elevationPenalty,
  onUpdate,
}: PacingSettingsProps) {
  const t = useTranslations("pacing");
  const fatiguePercent = toFatiguePercent(fatigueFactor);
  const elevationPercent = toElevationPercent(elevationPenalty);

  function handleFatigueChange(e: React.ChangeEvent<HTMLInputElement>) {
    const percent = Number(e.target.value);
    onUpdate(fromFatiguePercent(percent), elevationPenalty);
  }

  function handleElevationChange(e: React.ChangeEvent<HTMLInputElement>) {
    const percent = Number(e.target.value);
    onUpdate(fatigueFactor, fromElevationPercent(percent));
  }

  return (
    <TooltipProvider>
      <div className="flex flex-col gap-2 text-sm">
        <div className="flex items-center gap-2">
          <label
            htmlFor="fatigue-factor"
            className="text-muted-foreground whitespace-nowrap"
          >
            {t("fatigue")}
          </label>
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                className="text-muted-foreground cursor-help"
              >
                <HelpCircle className="h-3.5 w-3.5" />
              </button>
            </TooltipTrigger>
            <TooltipContent>
              {t("fatigueTooltip", { value: fatiguePercent })}
            </TooltipContent>
          </Tooltip>
          <input
            id="fatigue-factor"
            type="range"
            min={1}
            max={50}
            step={1}
            value={fatiguePercent}
            onChange={handleFatigueChange}
            className="h-2 w-24 accent-primary"
            aria-label={t("fatigueLabel")}
          />
          <span className="text-muted-foreground tabular-nums w-8 text-right">
            {fatiguePercent}%
          </span>
        </div>
        <div className="flex items-center gap-2">
          <label
            htmlFor="elevation-penalty"
            className="text-muted-foreground whitespace-nowrap"
          >
            {t("elevationPenalty")}
          </label>
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                className="text-muted-foreground cursor-help"
              >
                <HelpCircle className="h-3.5 w-3.5" />
              </button>
            </TooltipTrigger>
            <TooltipContent>
              {t("elevationTooltip", { value: elevationPercent })}
            </TooltipContent>
          </Tooltip>
          <input
            id="elevation-penalty"
            type="range"
            min={1}
            max={100}
            step={1}
            value={elevationPercent}
            onChange={handleElevationChange}
            className="h-2 w-24 accent-primary"
            aria-label={t("elevationLabel")}
          />
          <span className="text-muted-foreground tabular-nums w-8 text-right">
            {elevationPercent}%
          </span>
        </div>
      </div>
    </TooltipProvider>
  );
}
