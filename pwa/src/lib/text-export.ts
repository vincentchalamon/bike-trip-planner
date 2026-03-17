import type { StageData, AccommodationData } from "@/lib/validation/schemas";

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

function formatPriceRange(acc: AccommodationData): string | null {
  const min = Number(acc.estimatedPriceMin);
  const max = Number(acc.estimatedPriceMax);

  if (isNaN(min) || isNaN(max) || (min === 0 && max === 0)) return null;

  if (acc.isExactPrice || min === max) {
    return `${max}€`;
  }
  return `${min}-${max}€`;
}

function formatStageLine(stage: StageData, startDate: string | null): string {
  const date = formatDate(startDate, stage.dayNumber);
  const distance = `${Math.round(stage.distance)}km`;
  const elevUp = `⬆️ ${Math.round(stage.elevation)}m`;
  const elevDown = `⬇️ ${Math.round(stage.elevationLoss ?? 0)}m`;

  let line = `*${date}* : ${distance}, ${elevUp} ${elevDown}`;

  const acc = stage.selectedAccommodation ?? stage.accommodations[0] ?? null;

  if (acc) {
    const price = formatPriceRange(acc);
    let accPart = acc.name;
    if (acc.url) {
      accPart = `${acc.name} (${acc.url})`;
    }
    if (price) {
      accPart = `${accPart} ${price}`;
    }
    line = `${line}, ${accPart}`;
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
    for (const stage of activeStages) {
      lines.push(formatStageLine(stage, startDate));
    }
  }

  return lines.join("\n");
}
