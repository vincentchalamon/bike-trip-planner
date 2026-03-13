"use client";

import { useTranslations } from "next-intl";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { DIFFICULTY_COLORS } from "@/lib/constants";
import type { Difficulty } from "@/lib/constants";

interface DifficultyGaugeProps {
  difficulty: Difficulty;
  distance: number;
  elevation: number;
}

const difficultyDotClasses: Record<Difficulty, string> = {
  easy: "bg-green-500",
  medium: "bg-orange-500",
  hard: "bg-red-500",
};

const difficultyLabelKeys = {
  easy: "difficultyEasy",
  medium: "difficultyMedium",
  hard: "difficultyHard",
} as const;

export function DifficultyGauge({
  difficulty,
  distance,
  elevation,
}: DifficultyGaugeProps) {
  const t = useTranslations("stage");

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <div
          className={`inline-flex items-center gap-1.5 px-2 py-1 rounded-full cursor-default select-none ${DIFFICULTY_COLORS[difficulty]}`}
          aria-label={`${t(difficultyLabelKeys[difficulty])} — ${Math.round(distance)} km, D+ ${Math.round(elevation)} m`}
        >
          <span
            className={`h-1.5 w-1.5 rounded-full shrink-0 ${difficultyDotClasses[difficulty]}`}
          />
          <span className="text-xs font-medium">
            {t(difficultyLabelKeys[difficulty])}
          </span>
        </div>
      </TooltipTrigger>

      <TooltipContent side="bottom" className="text-xs">
        {t(difficultyLabelKeys[difficulty])} — {Math.round(distance)} km, D+{" "}
        {Math.round(elevation)} m
      </TooltipContent>
    </Tooltip>
  );
}
