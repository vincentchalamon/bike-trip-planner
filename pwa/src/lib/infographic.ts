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

export const CARD_WIDTH = 800;
export const CARD_HEIGHT = 480;
const PADDING = 28;
const MAP_WIDTH = 260;
const MAP_HEIGHT = 200;
const ELEV_HEIGHT = 88;
// Layout positions (fixed, always reserve space for the date line)
const SEP_Y = PADDING + 32 + 24 + 8; // 92
const CONTENT_TOP = SEP_Y + 16; // 108
const SEP2_Y = CONTENT_TOP + MAP_HEIGHT + 12; // 320
const ELEV_Y = SEP2_Y + 12; // 332

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
  const tempMin = temps.reduce(
    (a, w) => (w.tempMin < a ? w.tempMin : a),
    temps[0]!.tempMin,
  );
  const tempMax = temps.reduce(
    (a, w) => (w.tempMax > a ? w.tempMax : a),
    temps[0]!.tempMax,
  );

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

/**
 * Render a trip infographic summary to an HTMLCanvasElement.
 * Returns a Promise because it fetches OpenStreetMap tiles for the route map.
 * Attribution: © OpenStreetMap contributors (https://www.openstreetmap.org/copyright)
 */
export async function renderInfographic(
  canvas: HTMLCanvasElement,
  data: InfographicData,
): Promise<void> {
  const dpr = typeof window !== "undefined" ? window.devicePixelRatio || 1 : 2;
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

  // Date range subtitle (space always reserved so layout stays fixed)
  if (data.startDate || data.endDate) {
    ctx.fillStyle = "#94a3b8";
    ctx.font = "14px system-ui, -apple-system, sans-serif";
    ctx.fillText(
      formatDateRange(data.startDate, data.endDate),
      PADDING,
      PADDING + 32,
    );
  }

  // First separator
  ctx.strokeStyle = "#334155";
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(PADDING, SEP_Y);
  ctx.lineTo(CARD_WIDTH - PADDING, SEP_Y);
  ctx.stroke();

  // Route map (left side of content row) — async tile fetch
  await drawRouteMap(
    ctx,
    PADDING,
    CONTENT_TOP,
    MAP_WIDTH,
    MAP_HEIGHT,
    data.stages,
  );

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
    const sx = statsX + col * colWidth;
    const sy = CONTENT_TOP + row * rowHeight;

    ctx.font = "20px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = "#ffffff";
    ctx.textBaseline = "top";
    ctx.fillText(stat.icon, sx, sy);

    ctx.font = "11px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = "#64748b";
    ctx.fillText(stat.label, sx + 30, sy + 2);

    ctx.font = "bold 14px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = stat.color;
    ctx.fillText(
      truncateText(ctx, stat.value, colWidth - 38),
      sx + 30,
      sy + 18,
    );
  });

  // Second separator
  ctx.strokeStyle = "#334155";
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(PADDING, SEP2_Y);
  ctx.lineTo(CARD_WIDTH - PADDING, SEP2_Y);
  ctx.stroke();

  // Elevation profile
  drawElevationProfile(
    ctx,
    PADDING,
    ELEV_Y,
    CARD_WIDTH - PADDING * 2,
    ELEV_HEIGHT,
    data.stages,
  );

  // Footer / branding + OSM attribution
  ctx.fillStyle = "#475569";
  ctx.font = "11px system-ui, -apple-system, sans-serif";
  ctx.textBaseline = "bottom";
  ctx.fillText(
    `${data.labels.powered} \u00B7 \u00A9 OpenStreetMap contributors`,
    PADDING,
    CARD_HEIGHT - PADDING / 2,
  );
}

// ---------------------------------------------------------------------------
// Route map with OpenStreetMap tiles
// ---------------------------------------------------------------------------

/** Convert lon/lat to OSM tile coordinates at a given zoom level. */
function lonLatToTile(
  lon: number,
  lat: number,
  zoom: number,
): { x: number; y: number } {
  const x = Math.floor(((lon + 180) / 360) * Math.pow(2, zoom));
  const latRad = (lat * Math.PI) / 180;
  const y = Math.floor(
    ((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) *
      Math.pow(2, zoom),
  );
  return { x, y };
}

/** Choose the highest zoom where the bounding box fits in ≤ 9 tiles (3×3). */
function chooseZoom(
  minLat: number,
  maxLat: number,
  minLon: number,
  maxLon: number,
): number {
  for (let z = 13; z >= 1; z--) {
    const tMin = lonLatToTile(minLon, maxLat, z);
    const tMax = lonLatToTile(maxLon, minLat, z);
    const numX = Math.max(1, tMax.x - tMin.x + 1);
    const numY = Math.max(1, tMax.y - tMin.y + 1);
    if (numX * numY <= 9) return z;
  }
  return 4;
}

/** Load a tile image, resolving to null on error or timeout. */
function loadTile(url: string): Promise<HTMLImageElement | null> {
  if (typeof document === "undefined") return Promise.resolve(null);
  return new Promise((resolve) => {
    const img = new Image();
    img.crossOrigin = "anonymous";
    const timeout = setTimeout(() => resolve(null), 5000);
    img.onload = () => {
      clearTimeout(timeout);
      resolve(img);
    };
    img.onerror = () => {
      clearTimeout(timeout);
      resolve(null);
    };
    img.src = url;
  });
}

async function drawRouteMap(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  stages: StageData[],
): Promise<void> {
  // Dark fallback background
  ctx.fillStyle = "#0f2340";
  roundRect(ctx, x, y, w, h, 8);
  ctx.fill();

  const activeStages = stages.filter(
    (s) => !s.isRestDay && s.geometry.length >= 2,
  );
  if (activeStages.length === 0) return;

  const allPoints = activeStages.flatMap((s) => s.geometry);
  if (allPoints.length < 2) return;

  // Bounding box with 10 % padding
  const lats = allPoints.map((p) => p.lat);
  const lons = allPoints.map((p) => p.lon);
  const rawMinLat = lats.reduce((a, b) => (b < a ? b : a), lats[0]!);
  const rawMaxLat = lats.reduce((a, b) => (b > a ? b : a), lats[0]!);
  const rawMinLon = lons.reduce((a, b) => (b < a ? b : a), lons[0]!);
  const rawMaxLon = lons.reduce((a, b) => (b > a ? b : a), lons[0]!);
  const latPad = Math.max((rawMaxLat - rawMinLat) * 0.15, 0.01);
  const lonPad = Math.max((rawMaxLon - rawMinLon) * 0.15, 0.01);
  const minLat = rawMinLat - latPad;
  const maxLat = rawMaxLat + latPad;
  const minLon = rawMinLon - lonPad;
  const maxLon = rawMaxLon + lonPad;

  const zoom = chooseZoom(minLat, maxLat, minLon, maxLon);
  const power = Math.pow(2, zoom);

  const tileMin = lonLatToTile(minLon, maxLat, zoom); // top-left
  const tileMax = lonLatToTile(maxLon, minLat, zoom); // bottom-right
  const numTilesX = tileMax.x - tileMin.x + 1;
  const numTilesY = tileMax.y - tileMin.y + 1;

  // Scale tile grid to fit inside the map area
  const mapPad = 0;
  const mw = w - mapPad * 2;
  const mh = h - mapPad * 2;
  const scale = Math.min(mw / (numTilesX * 256), mh / (numTilesY * 256));
  const scaledW = numTilesX * 256 * scale;
  const scaledH = numTilesY * 256 * scale;
  const offX = x + mapPad + (mw - scaledW) / 2;
  const offY = y + mapPad + (mh - scaledH) / 2;

  /** Convert lon/lat to WebMercator absolute pixel at this zoom. */
  const toAbsPx = (lon: number, lat: number): [number, number] => {
    const px = ((lon + 180) / 360) * 256 * power;
    const latRad = (lat * Math.PI) / 180;
    const py =
      ((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) *
      256 *
      power;
    return [px, py];
  };

  const toCanvas = (lon: number, lat: number): [number, number] => {
    const [px, py] = toAbsPx(lon, lat);
    return [
      offX + (px - tileMin.x * 256) * scale,
      offY + (py - tileMin.y * 256) * scale,
    ];
  };

  // Fetch all tiles in parallel
  type TileResult = {
    tx: number;
    ty: number;
    img: HTMLImageElement | null;
  };
  const tilePromises: Promise<TileResult>[] = [];
  for (let tx = tileMin.x; tx <= tileMax.x; tx++) {
    for (let ty = tileMin.y; ty <= tileMax.y; ty++) {
      tilePromises.push(
        loadTile(`https://tile.openstreetmap.org/${zoom}/${tx}/${ty}.png`).then(
          (img) => ({ tx, ty, img }),
        ),
      );
    }
  }

  // Clip to rounded map rect
  ctx.save();
  roundRect(ctx, x, y, w, h, 8);
  ctx.clip();

  const tiles = await Promise.all(tilePromises);
  for (const { tx, ty, img } of tiles) {
    if (img) {
      ctx.drawImage(
        img,
        offX + (tx - tileMin.x) * 256 * scale,
        offY + (ty - tileMin.y) * 256 * scale,
        256 * scale,
        256 * scale,
      );
    }
  }

  // Draw route on top of tiles
  for (const stage of activeStages) {
    const color =
      DIFFICULTY_HEX[getDifficulty(stage.distance, stage.elevation)] ??
      "#38bdf8";
    ctx.strokeStyle = color;
    ctx.lineWidth = 3;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    ctx.beginPath();
    const first = stage.geometry[0]!;
    const [sx, sy] = toCanvas(first.lon, first.lat);
    ctx.moveTo(sx, sy);
    for (let i = 1; i < stage.geometry.length; i++) {
      const pt = stage.geometry[i]!;
      const [px, py] = toCanvas(pt.lon, pt.lat);
      ctx.lineTo(px, py);
    }
    ctx.stroke();
  }

  // Start marker (green) and end marker (red)
  const firstGeom = activeStages[0]!.geometry;
  const [startX, startY] = toCanvas(firstGeom[0]!.lon, firstGeom[0]!.lat);
  ctx.fillStyle = "#22c55e";
  ctx.beginPath();
  ctx.arc(startX, startY, 5, 0, Math.PI * 2);
  ctx.fill();

  const lastGeom = activeStages[activeStages.length - 1]!.geometry;
  const lastPt = lastGeom[lastGeom.length - 1]!;
  const [endX, endY] = toCanvas(lastPt.lon, lastPt.lat);
  ctx.fillStyle = "#ef4444";
  ctx.beginPath();
  ctx.arc(endX, endY, 5, 0, Math.PI * 2);
  ctx.fill();

  ctx.restore();
}

// ---------------------------------------------------------------------------
// Elevation profile
// ---------------------------------------------------------------------------

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

  // Downsample to at most 600 points
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

  const minEle = sampled.reduce((a, b) => (b < a ? b : a), sampled[0]!);
  const maxEle = sampled.reduce((a, b) => (b > a ? b : a), sampled[0]!);
  const eleRange = maxEle - minEle || 1;
  const padY = 4;
  const ph = h - padY * 2;
  const n = sampled.length;

  const toCanvasX = (i: number) => x + (i / (n - 1)) * w;
  const toCanvasY = (ele: number) =>
    y + h - padY - ((ele - minEle) / eleRange) * ph;

  const grad = ctx.createLinearGradient(0, y, 0, y + h);
  grad.addColorStop(0, "rgba(56, 189, 248, 0.35)");
  grad.addColorStop(1, "rgba(56, 189, 248, 0.0)");

  ctx.beginPath();
  ctx.moveTo(toCanvasX(0), toCanvasY(sampled[0]!));
  for (let i = 1; i < n; i++) {
    ctx.lineTo(toCanvasX(i), toCanvasY(sampled[i]!));
  }
  ctx.lineTo(toCanvasX(n - 1), y + h);
  ctx.lineTo(toCanvasX(0), y + h);
  ctx.closePath();
  ctx.fillStyle = grad;
  ctx.fill();

  ctx.beginPath();
  ctx.moveTo(toCanvasX(0), toCanvasY(sampled[0]!));
  for (let i = 1; i < n; i++) {
    ctx.lineTo(toCanvasX(i), toCanvasY(sampled[i]!));
  }
  ctx.strokeStyle = "#38bdf8";
  ctx.lineWidth = 1.5;
  ctx.stroke();
}

// ---------------------------------------------------------------------------
// Download helper
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Canvas utilities
// ---------------------------------------------------------------------------

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
