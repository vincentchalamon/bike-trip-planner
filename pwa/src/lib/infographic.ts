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
    dates: string;
    budget: string;
    difficulty: string;
    weather: string;
    difficultyEasy: string;
    difficultyMedium: string;
    difficultyHard: string;
    powered: string;
  };
}

const DIFFICULTY_HEX: Record<string, string> = {
  easy: "#22c55e",
  medium: "#f97316",
  hard: "#ef4444",
};

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

const CARD_WIDTH = 800;
const PADDING = 28;
const MAP_WIDTH = 260;
const MAP_HEIGHT = 200;
const ELEV_HEIGHT = 88;

/**
 * Render a trip infographic summary to an HTMLCanvasElement.
 * The caller is responsible for creating the canvas (or using an offscreen one).
 */
export function renderInfographic(
  canvas: HTMLCanvasElement,
  data: InfographicData,
): void {
  const dpr = typeof window !== "undefined" ? window.devicePixelRatio || 1 : 2;

  // Compute layout heights dynamically based on whether dates are shown
  let dateY = PADDING + 32;
  if (data.startDate || data.endDate) {
    dateY += 24;
  }
  const sepY = dateY + 8;
  const contentTop = sepY + 16;
  const sep2Y = contentTop + MAP_HEIGHT + 12;
  const elevY = sep2Y + 12;
  const CARD_HEIGHT = elevY + ELEV_HEIGHT + 24 + PADDING;

  canvas.width = CARD_WIDTH * dpr;
  canvas.height = CARD_HEIGHT * dpr;

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

  // Date range subtitle
  let headerDateY = PADDING + 32;
  if (data.startDate || data.endDate) {
    ctx.fillStyle = "#94a3b8";
    ctx.font = "14px system-ui, -apple-system, sans-serif";
    const dateStr = formatDateRange(data.startDate, data.endDate);
    ctx.fillText(dateStr, PADDING, headerDateY);
    headerDateY += 24;
  }

  // Separator line
  ctx.strokeStyle = "#334155";
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(PADDING, sepY);
  ctx.lineTo(CARD_WIDTH - PADDING, sepY);
  ctx.stroke();

  // Route map (left side of content row)
  drawRouteMap(ctx, PADDING, contentTop, MAP_WIDTH, MAP_HEIGHT, data.stages);

  // Stats grid (right side of content row)
  const statsX = PADDING + MAP_WIDTH + 16;
  const statsW = CARD_WIDTH - statsX - PADDING;
  const colWidth = statsW / 2;
  const rowHeight = MAP_HEIGHT / 3;

  const activeStages = data.stages.filter((s) => !s.isRestDay);
  const difficulty = computeOverallDifficulty(data.stages, data.labels);
  const weather = getWeatherSummary(data.stages);

  const datesValue =
    formatDateRange(data.startDate, data.endDate) || `${activeStages.length}`;

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
          : "\u2014",
      color: "#38bdf8",
    },
    {
      icon: "\u26F0\uFE0F",
      label: data.labels.elevation,
      value:
        data.totalElevation !== null
          ? `\u2B06 ${Math.round(data.totalElevation)}m \u2B07 ${Math.round(data.totalElevationLoss ?? 0)}m`
          : "\u2014",
      color: "#f97316",
    },
    {
      icon: "\uD83D\uDCC5",
      label: data.labels.dates,
      value: datesValue,
      color: "#a78bfa",
    },
    {
      icon: "\uD83C\uDF24\uFE0F",
      label: data.labels.weather,
      value: weather
        ? `${weather.description}, ${Math.round(weather.tempMin)}-${Math.round(weather.tempMax)}\u00B0C`
        : "\u2014",
      color: "#fbbf24",
    },
    {
      icon: "\uD83D\uDCB6",
      label: data.labels.budget,
      value:
        data.estimatedBudgetMin > 0 || data.estimatedBudgetMax > 0
          ? `${Math.round(data.estimatedBudgetMin)}\u2013${Math.round(data.estimatedBudgetMax)}\u20AC`
          : "\u2014",
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
    const x = statsX + col * colWidth;
    const y = contentTop + row * rowHeight;

    ctx.font = "20px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = "#ffffff";
    ctx.textBaseline = "top";
    ctx.fillText(stat.icon, x, y);

    ctx.font = "11px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = "#64748b";
    ctx.fillText(stat.label, x + 30, y + 2);

    ctx.font = "bold 14px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = stat.color;
    const valueText = truncateText(ctx, stat.value, colWidth - 38);
    ctx.fillText(valueText, x + 30, y + 18);
  });

  // Second separator
  ctx.strokeStyle = "#334155";
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(PADDING, sep2Y);
  ctx.lineTo(CARD_WIDTH - PADDING, sep2Y);
  ctx.stroke();

  // Elevation profile
  drawElevationProfile(
    ctx,
    PADDING,
    elevY,
    CARD_WIDTH - PADDING * 2,
    ELEV_HEIGHT,
    data.stages,
  );

  // Footer / branding
  ctx.fillStyle = "#475569";
  ctx.font = "11px system-ui, -apple-system, sans-serif";
  ctx.textBaseline = "bottom";
  ctx.fillText(data.labels.powered, PADDING, CARD_HEIGHT - PADDING / 2);
}

/**
 * Draw a route map for the given stages onto the canvas at the specified rect.
 */
function drawRouteMap(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  stages: StageData[],
): void {
  // Map background
  ctx.fillStyle = "#0f2340";
  roundRect(ctx, x, y, w, h, 8);
  ctx.fill();

  const activeStages = stages.filter(
    (s) => !s.isRestDay && s.geometry.length >= 2,
  );
  if (activeStages.length === 0) return;

  // Collect all coordinates
  const allPoints = activeStages.flatMap((s) => s.geometry);
  if (allPoints.length < 2) return;

  const lats = allPoints.map((p) => p.lat);
  const lons = allPoints.map((p) => p.lon);
  const minLat = Math.min(...lats);
  const maxLat = Math.max(...lats);
  const minLon = Math.min(...lons);
  const maxLon = Math.max(...lons);
  const latRange = maxLat - minLat || 0.001;
  const lonRange = maxLon - minLon || 0.001;

  const mapPad = 12;
  const mw = w - mapPad * 2;
  const mh = h - mapPad * 2;

  // Adjust for Mercator longitude compression at this latitude
  const avgLat = (minLat + maxLat) / 2;
  const cosLat = Math.cos((avgLat * Math.PI) / 180);
  const scaleX = mw / (lonRange * cosLat);
  const scaleY = mh / latRange;
  const scale = Math.min(scaleX, scaleY);

  const usedW = lonRange * cosLat * scale;
  const usedH = latRange * scale;
  const offX = x + mapPad + (mw - usedW) / 2;
  const offY = y + mapPad + (mh - usedH) / 2;

  const toCanvas = (lat: number, lon: number): [number, number] => [
    offX + (lon - minLon) * cosLat * scale,
    offY + usedH - (lat - minLat) * scale,
  ];

  // Draw each stage as a colored polyline
  for (const stage of activeStages) {
    const color =
      DIFFICULTY_HEX[getDifficulty(stage.distance, stage.elevation)] ??
      "#38bdf8";
    ctx.strokeStyle = color;
    ctx.lineWidth = 2;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    ctx.beginPath();
    // geometry.length >= 2 is guaranteed by the filter above
    const first = stage.geometry[0]!;
    const [sx, sy] = toCanvas(first.lat, first.lon);
    ctx.moveTo(sx, sy);
    for (let i = 1; i < stage.geometry.length; i++) {
      const pt = stage.geometry[i]!;
      const [px, py] = toCanvas(pt.lat, pt.lon);
      ctx.lineTo(px, py);
    }
    ctx.stroke();
  }

  // Start marker (green circle)
  const firstGeom = activeStages[0]!.geometry;
  const firstPt = firstGeom[0]!;
  const [sx, sy] = toCanvas(firstPt.lat, firstPt.lon);
  ctx.fillStyle = "#22c55e";
  ctx.beginPath();
  ctx.arc(sx, sy, 4, 0, Math.PI * 2);
  ctx.fill();

  // End marker (red circle)
  const lastGeom = activeStages[activeStages.length - 1]!.geometry;
  const lastPt = lastGeom[lastGeom.length - 1]!;
  const [ex, ey] = toCanvas(lastPt.lat, lastPt.lon);
  ctx.fillStyle = "#ef4444";
  ctx.beginPath();
  ctx.arc(ex, ey, 4, 0, Math.PI * 2);
  ctx.fill();
}

/**
 * Draw an elevation profile spanning the given rect.
 */
function drawElevationProfile(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  stages: StageData[],
): void {
  const activeStages = stages.filter((s) => !s.isRestDay);
  const allEles = activeStages.flatMap((s) => s.geometry.map((p) => p.ele));
  if (allEles.length < 2) return;

  // Downsample to at most 600 points for a smooth but performant chart
  const maxPoints = 600;
  const step = Math.max(1, Math.floor(allEles.length / maxPoints));
  const sampled: number[] = [];
  for (let i = 0; i < allEles.length; i += step) {
    sampled.push(allEles[i]!);
  }
  const lastSampled = sampled[sampled.length - 1];
  const lastEle = allEles[allEles.length - 1];
  if (lastSampled !== lastEle && lastEle !== undefined) {
    sampled.push(lastEle);
  }

  const minEle = Math.min(...sampled);
  const maxEle = Math.max(...sampled);
  const eleRange = maxEle - minEle || 1;

  const padY = 4;
  const ph = h - padY * 2;
  const n = sampled.length;

  const toCanvasX = (i: number) => x + (i / (n - 1)) * w;
  const toCanvasY = (ele: number) =>
    y + h - padY - ((ele - minEle) / eleRange) * ph;

  // Filled area with gradient
  const grad = ctx.createLinearGradient(0, y, 0, y + h);
  grad.addColorStop(0, "rgba(56, 189, 248, 0.35)");
  grad.addColorStop(1, "rgba(56, 189, 248, 0.0)");

  ctx.beginPath();
  // sampled is guaranteed non-empty (allEles.length >= 2 checked above)
  ctx.moveTo(toCanvasX(0), toCanvasY(sampled[0]!));
  for (let i = 1; i < n; i++) {
    ctx.lineTo(toCanvasX(i), toCanvasY(sampled[i]!));
  }
  ctx.lineTo(toCanvasX(n - 1), y + h);
  ctx.lineTo(toCanvasX(0), y + h);
  ctx.closePath();
  ctx.fillStyle = grad;
  ctx.fill();

  // Stroke on top
  ctx.beginPath();
  ctx.moveTo(toCanvasX(0), toCanvasY(sampled[0]!));
  for (let i = 1; i < n; i++) {
    ctx.lineTo(toCanvasX(i), toCanvasY(sampled[i]!));
  }
  ctx.strokeStyle = "#38bdf8";
  ctx.lineWidth = 1.5;
  ctx.stroke();
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
    // Append time component so date-only strings are parsed as local midnight,
    // not UTC midnight (which shifts the date backward in UTC- timezones).
    const d = new Date(iso.includes("T") ? iso : `${iso}T00:00:00`);
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
