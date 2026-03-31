import { getDifficulty } from "@/lib/constants";
import type { StageData } from "@/lib/validation/schemas";

export interface InfographicData {
  title: string;
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  stages: StageData[];
  startDate: string | null;
  endDate: string | null;
  estimatedBudgetMin: number;
  estimatedBudgetMax: number;
  labels: {
    distance: string;
    elevation: string;
    days: string;
    budget: string;
    difficulty: string;
    weather: string;
    difficultyEasy: string;
    difficultyMedium: string;
    difficultyHard: string;
    powered: string;
  };
}

function computeOverallDifficulty(
  stages: StageData[],
  labels: InfographicData["labels"],
): { label: string; color: string } {
  const activeStages = stages.filter((s) => !s.isRestDay);
  if (activeStages.length === 0) {
    return { label: labels.difficultyEasy, color: "#22c55e" };
  }

  const difficulties = activeStages.map((s) =>
    getDifficulty(s.distance, s.elevation),
  );
  const hardCount = difficulties.filter((d) => d === "hard").length;
  const mediumCount = difficulties.filter((d) => d === "medium").length;

  if (hardCount > activeStages.length * 0.3) {
    return { label: labels.difficultyHard, color: "#ef4444" };
  }
  if (mediumCount + hardCount > activeStages.length * 0.5) {
    return { label: labels.difficultyMedium, color: "#f97316" };
  }
  return { label: labels.difficultyEasy, color: "#22c55e" };
}

function getWeatherSummary(
  stages: StageData[],
): { tempMin: number; tempMax: number; description: string } | null {
  const withWeather = stages.filter((s) => s.weather && !s.isRestDay);
  if (withWeather.length === 0) return null;

  const temps = withWeather.map((s) => s.weather!);
  const tempMin = Math.min(...temps.map((w) => w.tempMin));
  const tempMax = Math.max(...temps.map((w) => w.tempMax));

  // Most frequent description
  const descCounts = new Map<string, number>();
  for (const w of temps) {
    descCounts.set(w.description, (descCounts.get(w.description) ?? 0) + 1);
  }
  let description = "";
  let maxCount = 0;
  for (const [desc, count] of descCounts) {
    if (count > maxCount) {
      description = desc;
      maxCount = count;
    }
  }

  return { tempMin, tempMax, description };
}

const CARD_WIDTH = 600;
const CARD_HEIGHT = 400;
const PADDING = 32;

/**
 * Render a trip infographic summary to an HTMLCanvasElement.
 * The caller is responsible for creating the canvas (or using an offscreen one).
 */
export function renderInfographic(
  canvas: HTMLCanvasElement,
  data: InfographicData,
): void {
  const dpr = typeof window !== "undefined" ? window.devicePixelRatio || 1 : 2;
  canvas.width = CARD_WIDTH * dpr;
  canvas.height = CARD_HEIGHT * dpr;
  canvas.style.width = `${CARD_WIDTH}px`;
  canvas.style.height = `${CARD_HEIGHT}px`;

  const ctx = canvas.getContext("2d");
  if (!ctx) return;

  ctx.scale(dpr, dpr);

  // Background gradient
  const gradient = ctx.createLinearGradient(0, 0, CARD_WIDTH, CARD_HEIGHT);
  gradient.addColorStop(0, "#1e293b");
  gradient.addColorStop(1, "#0f172a");
  ctx.fillStyle = gradient;
  roundRect(ctx, 0, 0, CARD_WIDTH, CARD_HEIGHT, 16);
  ctx.fill();

  // Title
  ctx.fillStyle = "#ffffff";
  ctx.font = "bold 22px system-ui, -apple-system, sans-serif";
  ctx.textBaseline = "top";
  const titleText = truncateText(ctx, data.title, CARD_WIDTH - PADDING * 2);
  ctx.fillText(titleText, PADDING, PADDING);

  // Date range (if available)
  let dateY = PADDING + 32;
  if (data.startDate || data.endDate) {
    ctx.fillStyle = "#94a3b8";
    ctx.font = "14px system-ui, -apple-system, sans-serif";
    const dateStr = formatDateRange(data.startDate, data.endDate);
    ctx.fillText(dateStr, PADDING, dateY);
    dateY += 24;
  }

  // Separator line
  const sepY = dateY + 8;
  ctx.strokeStyle = "#334155";
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(PADDING, sepY);
  ctx.lineTo(CARD_WIDTH - PADDING, sepY);
  ctx.stroke();

  // Stats grid (2 columns, 3 rows)
  const gridTop = sepY + 20;
  const colWidth = (CARD_WIDTH - PADDING * 2) / 2;
  const rowHeight = 72;

  const activeStages = data.stages.filter((s) => !s.isRestDay);
  const difficulty = computeOverallDifficulty(data.stages, data.labels);
  const weather = getWeatherSummary(data.stages);

  const stats: Array<{
    icon: string;
    label: string;
    value: string;
    color: string;
  }> = [
    {
      icon: "\uD83D\uDEB4",
      label: data.labels.distance,
      value:
        data.totalDistance !== null
          ? `${Math.round(data.totalDistance)} km`
          : "—",
      color: "#38bdf8",
    },
    {
      icon: "\u26F0\uFE0F",
      label: data.labels.elevation,
      value:
        data.totalElevation !== null
          ? `\u2B06 ${Math.round(data.totalElevation)}m \u2B07 ${Math.round(data.totalElevationLoss ?? 0)}m`
          : "—",
      color: "#f97316",
    },
    {
      icon: "\uD83D\uDCC5",
      label: data.labels.days,
      value: `${activeStages.length}`,
      color: "#a78bfa",
    },
    {
      icon: "\uD83C\uDF24\uFE0F",
      label: data.labels.weather,
      value: weather
        ? `${weather.description}, ${Math.round(weather.tempMin)}-${Math.round(weather.tempMax)}\u00B0C`
        : "—",
      color: "#fbbf24",
    },
    {
      icon: "\uD83D\uDCB6",
      label: data.labels.budget,
      value:
        data.estimatedBudgetMin > 0 || data.estimatedBudgetMax > 0
          ? `${Math.round(data.estimatedBudgetMin)}\u2013${Math.round(data.estimatedBudgetMax)}\u20AC`
          : "—",
      color: "#4ade80",
    },
    {
      icon: "\uD83D\uDCAA",
      label: data.labels.difficulty,
      value: difficulty.label,
      color: difficulty.color,
    },
  ];

  stats.forEach((stat, i) => {
    const col = i % 2;
    const row = Math.floor(i / 2);
    const x = PADDING + col * colWidth;
    const y = gridTop + row * rowHeight;

    // Icon
    ctx.font = "24px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = "#ffffff";
    ctx.fillText(stat.icon, x, y);

    // Label
    ctx.font = "12px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = "#64748b";
    ctx.fillText(stat.label, x + 34, y + 2);

    // Value
    ctx.font = "bold 16px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = stat.color;
    const valueText = truncateText(ctx, stat.value, colWidth - 44);
    ctx.fillText(valueText, x + 34, y + 20);
  });

  // Footer / branding
  ctx.fillStyle = "#475569";
  ctx.font = "11px system-ui, -apple-system, sans-serif";
  ctx.textBaseline = "bottom";
  ctx.fillText(data.labels.powered, PADDING, CARD_HEIGHT - PADDING / 2);
}

/**
 * Export the canvas content as a downloadable PNG file.
 */
export function downloadInfographicPng(
  canvas: HTMLCanvasElement,
  filename: string,
): void {
  const dataUrl = canvas.toDataURL("image/png");
  const a = document.createElement("a");
  a.href = dataUrl;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

function roundRect(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number,
): void {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.lineTo(x + w - r, y);
  ctx.quadraticCurveTo(x + w, y, x + w, y + r);
  ctx.lineTo(x + w, y + h - r);
  ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
  ctx.lineTo(x + r, y + h);
  ctx.quadraticCurveTo(x, y + h, x, y + h - r);
  ctx.lineTo(x, y + r);
  ctx.quadraticCurveTo(x, y, x + r, y);
  ctx.closePath();
}

function truncateText(
  ctx: CanvasRenderingContext2D,
  text: string,
  maxWidth: number,
): string {
  if (ctx.measureText(text).width <= maxWidth) return text;
  let truncated = text;
  while (
    truncated.length > 0 &&
    ctx.measureText(truncated + "\u2026").width > maxWidth
  ) {
    truncated = truncated.slice(0, -1);
  }
  return truncated + "\u2026";
}

function formatDateRange(start: string | null, end: string | null): string {
  const formatDate = (iso: string) => {
    const d = new Date(iso);
    return d.toLocaleDateString(undefined, {
      day: "numeric",
      month: "short",
      year: "numeric",
    });
  };

  if (start && end) {
    return `${formatDate(start)} \u2192 ${formatDate(end)}`;
  }
  if (start) return formatDate(start);
  return "";
}

