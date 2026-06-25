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
    difficultyEasy: string;
    difficultyMedium: string;
    difficultyHard: string;
    powered: string;
  };
}

/**
 * Per-stage palette. The elevation profile and route are coloured by stage
 * index (not difficulty) so that every stage gets a distinct colour even when
 * several stages share the same difficulty class.
 */
const STAGE_PALETTE = [
  "#38bdf8",
  "#f97316",
  "#a78bfa",
  "#4ade80",
  "#fb7185",
  "#facc15",
  "#22d3ee",
  "#c084fc",
];

/** Stable colour for a stage, cycling through {@link STAGE_PALETTE}. */
export function stageColor(index: number): string {
  return STAGE_PALETTE[index % STAGE_PALETTE.length]!;
}

export const CARD_WIDTH = 800;
export const CARD_HEIGHT = 480;
const PADDING = 28;
// 70 / 30 split of the content row: an enlarged map fills the left column, the
// stats and the elevation profile stack in the right column.
const CONTENT_WIDTH = CARD_WIDTH - PADDING * 2; // 744
const COLUMN_GAP = 16;
const MAP_WIDTH = Math.round((CONTENT_WIDTH - COLUMN_GAP) * 0.7); // ~510
// Layout positions (fixed, always reserve space for the date line)
const SEP_Y = PADDING + 32 + 24 + 8; // 92
const CONTENT_TOP = SEP_Y + 16; // 108
// Reserve a line under the map for the OSM attribution and a footer band for
// the branding line, then let the map fill the rest of the left column.
const MAP_ATTRIBUTION_H = 16;
const FOOTER_BAND_H = 28;
const CONTENT_BOTTOM = CARD_HEIGHT - FOOTER_BAND_H; // 452
const MAP_HEIGHT = CONTENT_BOTTOM - CONTENT_TOP - MAP_ATTRIBUTION_H; // 328
// Right column: fixed-height stat rows, then the elevation profile fills the
// remaining height down to the map's baseline.
const STAT_ROW_H = 40;
const ELEV_GAP = 16;

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
  ctx.fillRect(0, 0, CARD_WIDTH, CARD_HEIGHT);

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

  // Route map (left side of content row, ~70% width) — async tile fetch
  await drawRouteMap(
    ctx,
    PADDING,
    CONTENT_TOP,
    MAP_WIDTH,
    MAP_HEIGHT,
    data.stages,
  );

  // OSM attribution, directly under the map
  ctx.fillStyle = "#475569";
  ctx.font = "10px system-ui, -apple-system, sans-serif";
  ctx.textBaseline = "top";
  ctx.fillText(
    "© OpenStreetMap contributors",
    PADDING,
    CONTENT_TOP + MAP_HEIGHT + 4,
  );

  // Right column (~30% width): stats stacked above the elevation profile.
  const statsX = PADDING + MAP_WIDTH + COLUMN_GAP;
  const colWidth = CARD_WIDTH - statsX - PADDING;

  const activeStages = data.stages.filter((s) => !s.isRestDay);
  const difficulty = computeOverallDifficulty(data.stages, data.labels);
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
    const sx = statsX;
    const sy = CONTENT_TOP + i * STAT_ROW_H;

    ctx.font = "18px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = "#ffffff";
    ctx.textBaseline = "top";
    ctx.fillText(stat.icon, sx, sy);

    ctx.font = "11px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = "#64748b";
    ctx.fillText(stat.label, sx + 28, sy);

    ctx.font = "bold 14px system-ui, -apple-system, sans-serif";
    ctx.fillStyle = stat.color;
    ctx.fillText(
      truncateText(ctx, stat.value, colWidth - 28),
      sx + 28,
      sy + 15,
    );
  });

  // Divider between the stats and the elevation profile (right column only).
  const statsBottom = CONTENT_TOP + stats.length * STAT_ROW_H;
  ctx.strokeStyle = "#334155";
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(statsX, statsBottom + ELEV_GAP / 2);
  ctx.lineTo(CARD_WIDTH - PADDING, statsBottom + ELEV_GAP / 2);
  ctx.stroke();

  // Elevation profile — under the right column, its baseline aligned with the
  // bottom of the enlarged map.
  const elevY = statsBottom + ELEV_GAP;
  drawElevationProfile(
    ctx,
    statsX,
    elevY,
    colWidth,
    CONTENT_TOP + MAP_HEIGHT - elevY,
    data.stages,
  );

  // Footer / branding (OSM attribution is rendered under the map)
  ctx.fillStyle = "#475569";
  ctx.font = "11px system-ui, -apple-system, sans-serif";
  ctx.textBaseline = "bottom";
  ctx.fillText(
    `\u00A9 ${data.labels.powered}`,
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
  ctx.fillRect(x, y, w, h);

  const activeStages = stages.filter(
    (s) => !s.isRestDay && s.geometry.length >= 2,
  );
  if (activeStages.length === 0) return;

  const allPoints = activeStages.flatMap((s) => s.geometry);
  if (allPoints.length < 2) return;

  // Bounding box with 8 % padding
  const lats = allPoints.map((p) => p.lat);
  const lons = allPoints.map((p) => p.lon);
  const rawMinLat = lats.reduce((a, b) => (b < a ? b : a), lats[0]!);
  const rawMaxLat = lats.reduce((a, b) => (b > a ? b : a), lats[0]!);
  const rawMinLon = lons.reduce((a, b) => (b < a ? b : a), lons[0]!);
  const rawMaxLon = lons.reduce((a, b) => (b > a ? b : a), lons[0]!);
  const latPad = Math.max((rawMaxLat - rawMinLat) * 0.08, 0.005);
  const lonPad = Math.max((rawMaxLon - rawMinLon) * 0.08, 0.005);
  const minLat = rawMinLat - latPad;
  const maxLat = rawMaxLat + latPad;
  const minLon = rawMinLon - lonPad;
  const maxLon = rawMaxLon + lonPad;

  const zoom = chooseZoom(minLat, maxLat, minLon, maxLon);
  const power = Math.pow(2, zoom);

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

  // Scale so the padded bounding box (in absolute pixels) fits exactly in the
  // map area. This ensures the route is centred with minimal whitespace,
  // regardless of how many OSM tiles the bounding box spans.
  const [bboxMinPxX, bboxMinPxY] = toAbsPx(minLon, maxLat); // top-left
  const [bboxMaxPxX, bboxMaxPxY] = toAbsPx(maxLon, minLat); // bottom-right
  const bboxPxW = bboxMaxPxX - bboxMinPxX;
  const bboxPxH = bboxMaxPxY - bboxMinPxY;
  const scale = Math.min(w / bboxPxW, h / bboxPxH);
  const scaledBboxW = bboxPxW * scale;
  const scaledBboxH = bboxPxH * scale;
  const offX = x + (w - scaledBboxW) / 2;
  const offY = y + (h - scaledBboxH) / 2;

  const toCanvas = (lon: number, lat: number): [number, number] => {
    const [px, py] = toAbsPx(lon, lat);
    return [offX + (px - bboxMinPxX) * scale, offY + (py - bboxMinPxY) * scale];
  };

  const tileMin = lonLatToTile(minLon, maxLat, zoom); // top-left tile
  const tileMax = lonLatToTile(maxLon, minLat, zoom); // bottom-right tile

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

  // Clip to the map rect so tiles never bleed into the stats column.
  ctx.save();
  ctx.beginPath();
  ctx.rect(x, y, w, h);
  ctx.clip();

  const tiles = await Promise.all(tilePromises);
  for (const { tx, ty, img } of tiles) {
    if (img) {
      // Convert tile top-left corner (lon/lat) to canvas coordinates
      const tileLon = (tx / Math.pow(2, zoom)) * 360 - 180;
      const tileLatRad = Math.atan(
        Math.sinh(Math.PI * (1 - (2 * ty) / Math.pow(2, zoom))),
      );
      const tileLat = (tileLatRad * 180) / Math.PI;
      const [cx, cy] = toCanvas(tileLon, tileLat);
      ctx.drawImage(img, cx, cy, 256 * scale, 256 * scale);
    }
  }

  // Draw route on top of tiles (one colour per stage)
  activeStages.forEach((stage, stageIdx) => {
    ctx.strokeStyle = stageColor(stageIdx);
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
  });

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
  if (activeStages.length === 0) return;

  // Build per-stage segments with elevation + one colour per stage (by index,
  // so every stage is visually distinct even when several share a difficulty).
  type Segment = { eles: number[]; color: string };
  const segments: Segment[] = activeStages.map((s, i) => ({
    eles: s.geometry.map((p) => p.ele),
    color: stageColor(i),
  }));

  const allEles = segments.flatMap((seg) => seg.eles);
  if (allEles.length < 2) return;

  const minEle = allEles.reduce((a, b) => (b < a ? b : a), allEles[0]!);
  const maxEle = allEles.reduce((a, b) => (b > a ? b : a), allEles[0]!);
  const eleRange = maxEle - minEle || 1;
  const padY = 4;
  const ph = h - padY * 2;
  const totalPoints = allEles.length;

  const toCanvasX = (globalIdx: number) =>
    x + (globalIdx / (totalPoints - 1)) * w;
  const toCanvasY = (ele: number) =>
    y + h - padY - ((ele - minEle) / eleRange) * ph;

  // Filled area under the profile (subtle gradient per segment)
  let globalIdx = 0;
  for (const seg of segments) {
    if (seg.eles.length < 2) {
      globalIdx += seg.eles.length;
      continue;
    }
    ctx.beginPath();
    ctx.moveTo(toCanvasX(globalIdx), toCanvasY(seg.eles[0]!));
    for (let j = 1; j < seg.eles.length; j++) {
      ctx.lineTo(toCanvasX(globalIdx + j), toCanvasY(seg.eles[j]!));
    }
    ctx.lineTo(toCanvasX(globalIdx + seg.eles.length - 1), y + h);
    ctx.lineTo(toCanvasX(globalIdx), y + h);
    ctx.closePath();
    // Parse hex color for semi-transparent fill
    const r = parseInt(seg.color.slice(1, 3), 16);
    const g = parseInt(seg.color.slice(3, 5), 16);
    const b = parseInt(seg.color.slice(5, 7), 16);
    const grad = ctx.createLinearGradient(0, y, 0, y + h);
    grad.addColorStop(0, `rgba(${r}, ${g}, ${b}, 0.3)`);
    grad.addColorStop(1, `rgba(${r}, ${g}, ${b}, 0.0)`);
    ctx.fillStyle = grad;
    ctx.fill();
    globalIdx += seg.eles.length;
  }

  // Stroke line per segment with its per-stage color
  globalIdx = 0;
  for (const seg of segments) {
    if (seg.eles.length < 2) {
      globalIdx += seg.eles.length;
      continue;
    }
    ctx.beginPath();
    ctx.moveTo(toCanvasX(globalIdx), toCanvasY(seg.eles[0]!));
    for (let j = 1; j < seg.eles.length; j++) {
      ctx.lineTo(toCanvasX(globalIdx + j), toCanvasY(seg.eles[j]!));
    }
    ctx.strokeStyle = seg.color;
    ctx.lineWidth = 1.5;
    ctx.stroke();
    globalIdx += seg.eles.length;
  }
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
