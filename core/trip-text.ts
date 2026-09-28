import type { StageData } from "./schemas";
import { MEAL_COST_MIN, MEAL_COST_MAX, mealsForStage } from "./budget";
import { stageDate } from "./stage-dates";

// Formatted trip text shared by web and mobile (ADR-055). Framework-free — no
// React/RN/Next imports. Produces the plain-text summary (title, totals,
// per-stage line with budget) both platforms copy/share; each platform appends
// its own share link separately.

function formatDate(
  startDate: string | null,
  dayNumber: number,
  locale: string,
  today: string,
): string {
  // Without a start date the days are counted from today.
  const day = stageDate(startDate ?? today, dayNumber) ?? today;
  return new Date(`${day}T00:00:00Z`).toLocaleDateString(locale, {
    timeZone: "UTC",
    weekday: "short",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

function formatStageLine(
  stage: StageData,
  startDate: string | null,
  stageIndex: number,
  totalActiveStages: number,
  locale: string,
  today: string,
): string {
  const isFirst = stageIndex === 0;
  const isLast = stageIndex === totalActiveStages - 1;
  const date = formatDate(startDate, stage.dayNumber, locale, today);
  const distance = `${Math.round(stage.distance)}km`;
  const elevUp = `⬆️ ${Math.round(stage.elevation)}m`;
  const elevDown = `⬇️ ${Math.round(stage.elevationLoss ?? 0)}m`;

  let line = `${date} : ${distance}, ${elevUp} ${elevDown}`;

  const acc = isLast
    ? null
    : (stage.selectedAccommodation ?? stage.accommodations[0] ?? null);

  const meals = mealsForStage(isFirst, isLast);
  const foodMin = meals * MEAL_COST_MIN;
  const foodMax = meals * MEAL_COST_MAX;

  if (acc) {
    const accMin = Number(acc.estimatedPriceMin);
    const accMax = Number(acc.estimatedPriceMax);
    const hasAccPrice =
      !isNaN(accMin) && !isNaN(accMax) && (accMin > 0 || accMax > 0);

    let accPart = acc.name;
    if (acc.url) {
      accPart = `${acc.name} (${acc.url})`;
    }

    if (hasAccPrice) {
      const totalMin = Math.round(accMin + foodMin);
      const totalMax = Math.round(accMax + foodMax);
      const budgetStr =
        acc.isExactPrice || totalMin === totalMax
          ? `${totalMax}€`
          : `${totalMin}-${totalMax}€`;
      accPart = `${accPart} ${budgetStr}`;
    }

    line = `${line}, ${accPart}`;
  } else {
    // Last stage or no accommodation found: food budget only
    line = `${line}, ${foodMin}-${foodMax}€`;
  }

  return line;
}

export interface TextExportParams {
  title: string;
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  sourceUrl: string;
  stages: StageData[];
  startDate: string | null;
  /** BCP 47 locale the stage dates are written in. */
  locale: string;
  /** `YYYY-MM-DD` the days are counted from when the trip has no start date. */
  today: string;
  labels: {
    totalDistance: string;
    totalElevation: string;
  };
}

export function buildTripText(params: TextExportParams): string {
  const {
    title,
    totalDistance,
    totalElevation,
    totalElevationLoss,
    sourceUrl,
    stages,
    startDate,
    locale,
    today,
    labels,
  } = params;

  const lines: string[] = [];

  // Title
  lines.push(title);
  lines.push("");

  // Global stats
  if (totalDistance !== null) {
    lines.push(`- 🚴 ${labels.totalDistance} : ${Math.round(totalDistance)}km`);
  }
  if (totalElevation !== null) {
    lines.push(
      `- 🏔 ${labels.totalElevation} : ⬆️ ${Math.round(totalElevation)}m ⬇️ ${Math.round(totalElevationLoss ?? 0)}m`,
    );
  }
  if (sourceUrl) {
    lines.push(`- 🧭 ${sourceUrl}`);
  }

  // Stages (skip rest days)
  const activeStages = stages.filter((s) => !s.isRestDay);
  if (activeStages.length > 0) {
    lines.push("");
    activeStages.forEach((stage, i) => {
      lines.push(
        formatStageLine(
          stage,
          startDate,
          i,
          activeStages.length,
          locale,
          today,
        ),
      );
    });
  }

  return lines.join("\n");
}
