"use client";

import { useTranslations } from "next-intl";
import { HelpCircle } from "lucide-react";
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { Switch } from "@/components/ui/switch";
import { Button } from "@/components/ui/button";

interface PacingSettingsProps {
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
  ebikeMode: boolean;
  onUpdate: (
    fatigueFactor: number,
    elevationPenalty: number,
    maxDistancePerDay: number,
    averageSpeed: number,
  ) => void;
  onEbikeModeChange: (ebikeMode: boolean) => void;
}

interface RiderPreset {
  key: "beginner" | "intermediate" | "expert";
  maxDistancePerDay: number;
  averageSpeed: number;
  elevationPenaltyPercent: number;
  fatiguePercent: number;
}

const PRESETS: RiderPreset[] = [
  {
    key: "beginner",
    maxDistancePerDay: 50,
    averageSpeed: 10,
    elevationPenaltyPercent: 30,
    fatiguePercent: 30,
  },
  {
    key: "intermediate",
    maxDistancePerDay: 80,
    averageSpeed: 15,
    elevationPenaltyPercent: 20,
    fatiguePercent: 20,
  },
  {
    key: "expert",
    maxDistancePerDay: 120,
    averageSpeed: 20,
    elevationPenaltyPercent: 10,
    fatiguePercent: 10,
  },
];

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

function isIncoherent(averageSpeed: number, maxDistancePerDay: number): boolean {
  return averageSpeed < 8 && maxDistancePerDay > 100;
}

export function PacingSettings({
  fatigueFactor,
  elevationPenalty,
  maxDistancePerDay,
  averageSpeed,
  ebikeMode,
  onUpdate,
  onEbikeModeChange,
}: PacingSettingsProps) {
  const t = useTranslations("pacing");
  const fatiguePercent = toFatiguePercent(fatigueFactor);
  const elevationPercent = toElevationPercent(elevationPenalty);

  function handleFatigueChange(e: React.ChangeEvent<HTMLInputElement>) {
    const percent = Number(e.target.value);
    onUpdate(fromFatiguePercent(percent), elevationPenalty, maxDistancePerDay, averageSpeed);
  }

  function handleElevationChange(e: React.ChangeEvent<HTMLInputElement>) {
    const percent = Number(e.target.value);
    onUpdate(fatigueFactor, fromElevationPercent(percent), maxDistancePerDay, averageSpeed);
  }

  function handleMaxDistanceChange(e: React.ChangeEvent<HTMLInputElement>) {
    const value = Number(e.target.value);
    onUpdate(fatigueFactor, elevationPenalty, value, averageSpeed);
  }

  function handleAverageSpeedChange(e: React.ChangeEvent<HTMLInputElement>) {
    const value = Number(e.target.value);
    onUpdate(fatigueFactor, elevationPenalty, maxDistancePerDay, value);
  }

  function handlePreset(preset: RiderPreset) {
    onUpdate(
      fromFatiguePercent(preset.fatiguePercent),
      fromElevationPercent(preset.elevationPenaltyPercent),
      preset.maxDistancePerDay,
      preset.averageSpeed,
    );
  }

  const showCoherenceWarning = isIncoherent(averageSpeed, maxDistancePerDay);

  return (
    <TooltipProvider>
      <div className="flex flex-col gap-2 text-sm">
        {/* Presets */}
        <div className="flex items-center gap-2 flex-wrap">
          <span className="text-muted-foreground whitespace-nowrap">
            {t("profile")}
          </span>
          {PRESETS.map((preset) => (
            <Button
              key={preset.key}
              type="button"
              variant="outline"
              size="sm"
              className="h-6 px-2 text-xs"
              onClick={() => handlePreset(preset)}
              aria-label={t("presetLabel", { preset: t(`preset_${preset.key}`) })}
            >
              {t(`preset_${preset.key}`)}
            </Button>
          ))}
        </div>

        {/* Coherence warning */}
        {showCoherenceWarning && (
          <p className="text-xs text-amber-600 dark:text-amber-400" role="alert">
            {t("coherenceWarning")}
          </p>
        )}

        {/* Max distance per day */}
        <div className="flex items-center gap-2">
          <label
            htmlFor="max-distance-per-day"
            className="text-muted-foreground whitespace-nowrap"
          >
            {t("maxDistance")}
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
              {t("maxDistanceTooltip")}
            </TooltipContent>
          </Tooltip>
          <input
            id="max-distance-per-day"
            type="range"
            min={10}
            max={300}
            step={5}
            value={maxDistancePerDay}
            onChange={handleMaxDistanceChange}
            className="h-2 w-24 accent-primary"
            aria-label={t("maxDistanceLabel")}
          />
          <span className="text-muted-foreground tabular-nums w-12 text-right">
            {maxDistancePerDay} km
          </span>
        </div>

        {/* Average speed */}
        <div className="flex items-center gap-2">
          <label
            htmlFor="average-speed"
            className="text-muted-foreground whitespace-nowrap"
          >
            {t("averageSpeed")}
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
              {t("averageSpeedTooltip")}
            </TooltipContent>
          </Tooltip>
          <input
            id="average-speed"
            type="range"
            min={5}
            max={50}
            step={1}
            value={averageSpeed}
            onChange={handleAverageSpeedChange}
            className="h-2 w-24 accent-primary"
            aria-label={t("averageSpeedLabel")}
          />
          <span className="text-muted-foreground tabular-nums w-16 text-right">
            {averageSpeed} km/h
          </span>
        </div>

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
        <div className="flex items-center gap-2">
          <label
            htmlFor="ebike-mode"
            className="text-muted-foreground whitespace-nowrap"
          >
            {t("ebikeMode")}
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
            <TooltipContent>{t("ebikeModeTooltip")}</TooltipContent>
          </Tooltip>
          <Switch
            id="ebike-mode"
            size="sm"
            checked={ebikeMode}
            onCheckedChange={onEbikeModeChange}
            aria-label={t("ebikeModeLabel")}
          />
        </div>
      </div>
    </TooltipProvider>
  );
}
