import type { StageData } from "@/lib/validation/schemas";
import { MEAL_COST_MIN, MEAL_COST_MAX, mealsForStage } from "@/lib/budget-constants";

function formatDate(startDate: string | null, dayNumber: number): string {
  const [year = 0, month = 0, day = 0] = (
    startDate ??
    (() => {
      const n = new Date();
      return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, "0")}-${String(n.getDate()).padStart(2, "0")}`;
    })()
  )
    .split("-")
    .map(Number);
  const base = new Date(year, month - 1, day);
  const date = new Date(
    base.getFullYear(),
    base.getMonth(),
    base.getDate() + dayNumber - 1,
  );
  return date.toLocaleDateString(undefined, {
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
): string {
  const isFirst = stageIndex === 0;
  const isLast = stageIndex === totalActiveStages - 1;
  const date = formatDate(startDate, stage.dayNumber);
  const distance = `${Math.round(stage.distance)}km`;
  const elevUp = `⬆️ ${Math.round(stage.elevation)}m`;
  const elevDown = `⬇️ ${Math.round(stage.elevationLoss ?? 0)}m`;

  let line = `*${date}* : ${distance}, ${elevUp} ${elevDown}`;

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
    labels,
  } = params;

  const lines: string[] = [];

  // Title
  lines.push(`*${title}*`);
  lines.push("");

  // Global stats
  if (totalDistance !== null) {
    lines.push(`- 🚴‍ ${labels.totalDistance} : ${Math.round(totalDistance)}km`);
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
      lines.push(formatStageLine(stage, startDate, i, activeStages.length));
    });
  }

  return lines.join("\n");
}
