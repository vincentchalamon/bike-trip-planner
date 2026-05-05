/**
 * Square 1080×1080 infographic export — optimised for Instagram, WhatsApp,
 * Telegram and other social messaging apps where a 1:1 aspect ratio is
 * preferred.
 *
 * The template is intentionally readable without context (no login required)
 * and reuses the brand tokens defined in `src/app/globals.css`.
 *
 * Implementation: pure 2D canvas rendering (no `html2canvas`). This keeps the
 * client bundle small and reuses the OSM tile fetching strategy already used
 * by `lib/infographic.ts` for the landscape variant.
 *
 * Attribution: © OpenStreetMap contributors
 *   (https://www.openstreetmap.org/copyright)
 */

import { getDifficulty } from "@/lib/constants";
import type { StageData } from "@/lib/validation/schemas";

export interface SquareInfographicLabels {
  distance: string;
  elevation: string;
  days: string;
  budget: string;
  stagesHeading: string;
  restDay: string;
  /** Already-localised line displayed when more than {@link MAX_STAGE_LIST}
   * stages exist (e.g. "+ 2 more stages"). The renderer prints it verbatim. */
  more: string;
  poweredBy: string;
}

export interface SquareInfographicData {
  title: string;
  totalDistance: number | null;
  totalElevation: number | null;
  stages: StageData[];
  estimatedBudgetMin: number;
  estimatedBudgetMax: number;
  labels: SquareInfographicLabels;
}

/** Output dimensions in CSS pixels (the canvas is scaled by devicePixelRatio). */
export const SQUARE_INFOGRAPHIC_SIZE = 1080;

/** Maximum number of stages listed in the summary block; remaining stages
 * are summarised as "+ N more". */
export const MAX_STAGE_LIST = 6;

const PADDING = 56;

const DIFFICULTY_HEX: Record<string, string> = {
  easy: "#22c55e",
  medium: "#f97316",
  hard: "#ef4444",
};

/** Brand palette mirrored from globals.css (light mode) so the export looks
 * consistent regardless of which theme the user is viewing. */
const COLORS = {
  surface: "#faf7f0",
  ink: "#1a1814",
  inkSoft: "#5a5249",
  brand: "#c2671e",
  brandSoft: "#fdf0e6",
  border: "#e8dfd0",
  mapFallback: "#e6e0d0",
} as const;

/**
 * Render the 1080×1080 square infographic to the supplied canvas element.
 *
 * Returns a promise because the route map fetches OSM tiles asynchronously.
 */
export async function renderSquareInfographic(
  canvas: HTMLCanvasElement,
  data: SquareInfographicData,
): Promise<void> {
  const dpr = typeof window !== "undefined" ? window.devicePixelRatio || 1 : 2;
  canvas.width = SQUARE_INFOGRAPHIC_SIZE * dpr;
  canvas.height = SQUARE_INFOGRAPHIC_SIZE * dpr;

  const ctx = canvas.getContext("2d");
  if (!ctx) return;

  ctx.scale(dpr, dpr);
  ctx.textBaseline = "top";

  // ── Background (warm paper) ──────────────────────────────────────────────
  ctx.fillStyle = COLORS.surface;
  ctx.fillRect(0, 0, SQUARE_INFOGRAPHIC_SIZE, SQUARE_INFOGRAPHIC_SIZE);

  // ── Title (Fraunces serif, large) ────────────────────────────────────────
  // Title block has a fixed reserved height (1 or 2 lines) so the rest of
  // the layout stays deterministic regardless of trip name length.
  ctx.fillStyle = COLORS.ink;
  ctx.font = `700 48px "Fraunces", "Times New Roman", Georgia, serif`;
  const titleLines = wrapText(
    ctx,
    data.title || "—",
    SQUARE_INFOGRAPHIC_SIZE - PADDING * 2,
    2,
  );
  let titleY = PADDING;
  for (const line of titleLines) {
    ctx.fillText(line, PADDING, titleY);
    titleY += 56;
  }

  // Brand accent rule pinned below the reserved 2-line title slot
  let cursorY = PADDING + 56 * 2 + 8;
  ctx.fillStyle = COLORS.brand;
  ctx.fillRect(PADDING, cursorY, 96, 4);
  cursorY += 20;

  // ── Mini-map ─────────────────────────────────────────────────────────────
  const mapSize = 400;
  const mapX = (SQUARE_INFOGRAPHIC_SIZE - mapSize) / 2;
  const mapY = cursorY;
  await drawSquareRouteMap(ctx, mapX, mapY, mapSize, mapSize, data.stages);
  cursorY = mapY + mapSize + 20;

  // ── Stats grid (2×2) ─────────────────────────────────────────────────────
  const activeStages = data.stages.filter((s) => !s.isRestDay);
  const dayCount = activeStages.length;
  const stats: Array<{ label: string; value: string }> = [
    {
      label: data.labels.distance,
      value:
        data.totalDistance !== null
          ? `${Math.round(data.totalDistance)} km`
          : "—",
    },
    {
      label: data.labels.elevation,
      value:
        data.totalElevation !== null
          ? `${Math.round(data.totalElevation)} m D+`
          : "—",
    },
    {
      label: data.labels.days,
      value: dayCount > 0 ? String(dayCount) : "—",
    },
    {
      label: data.labels.budget,
      value:
        data.estimatedBudgetMin > 0 || data.estimatedBudgetMax > 0
          ? `${Math.round(data.estimatedBudgetMin)}–${Math.round(data.estimatedBudgetMax)} €`
          : "—",
    },
  ];

  const statColW = (SQUARE_INFOGRAPHIC_SIZE - PADDING * 2 - 16) / 2;
  const statRowH = 76;
  stats.forEach((stat, i) => {
    const col = i % 2;
    const row = Math.floor(i / 2);
    const sx = PADDING + col * (statColW + 16);
    const sy = cursorY + row * (statRowH + 10);

    // Card background
    ctx.fillStyle = COLORS.brandSoft;
    roundRect(ctx, sx, sy, statColW, statRowH, 10);
    ctx.fill();

    ctx.fillStyle = COLORS.inkSoft;
    ctx.font = `500 14px "Inter Tight", system-ui, -apple-system, sans-serif`;
    ctx.fillText(stat.label.toUpperCase(), sx + 16, sy + 12);

    ctx.fillStyle = COLORS.ink;
    ctx.font = `700 26px "Inter Tight", system-ui, -apple-system, sans-serif`;
    ctx.fillText(
      truncateText(ctx, stat.value, statColW - 32),
      sx + 16,
      sy + 36,
    );
  });
  cursorY += statRowH * 2 + 10 + 24;

  // ── Stage summary list (max MAX_STAGE_LIST entries) ─────────────────────
  ctx.fillStyle = COLORS.ink;
  ctx.font = `600 18px "Inter Tight", system-ui, -apple-system, sans-serif`;
  ctx.fillText(data.labels.stagesHeading.toUpperCase(), PADDING, cursorY);
  cursorY += 26;

  const visibleStages = activeStages.slice(0, MAX_STAGE_LIST);
  const rowGap = 2;
  const rowH = 28;
  for (const stage of visibleStages) {
    drawStageRow(
      ctx,
      PADDING,
      cursorY,
      SQUARE_INFOGRAPHIC_SIZE - PADDING * 2,
      rowH,
      stage,
      data.labels,
    );
    cursorY += rowH + rowGap;
  }
  if (activeStages.length > MAX_STAGE_LIST) {
    ctx.fillStyle = COLORS.inkSoft;
    ctx.font = `500 16px "Inter Tight", system-ui, -apple-system, sans-serif`;
    ctx.fillText(data.labels.more, PADDING, cursorY + 4);
  }

  // ── Footer / branding (bottom-right) ─────────────────────────────────────
  ctx.fillStyle = COLORS.brand;
  ctx.font = `700 22px "Fraunces", Georgia, serif`;
  ctx.textAlign = "right";
  ctx.textBaseline = "bottom";
  ctx.fillText(
    data.labels.poweredBy,
    SQUARE_INFOGRAPHIC_SIZE - PADDING,
    SQUARE_INFOGRAPHIC_SIZE - PADDING + 4,
  );

  // OSM attribution (bottom-left, small)
  ctx.fillStyle = COLORS.inkSoft;
  ctx.font = `400 12px "Inter Tight", system-ui, -apple-system, sans-serif`;
  ctx.textAlign = "left";
  ctx.fillText(
    "© OpenStreetMap contributors",
    PADDING,
    SQUARE_INFOGRAPHIC_SIZE - PADDING + 4,
  );

  ctx.textAlign = "left";
  ctx.textBaseline = "top";
}

/** Draw a single stage summary row: "Day N · Label … km · m D+". */
function drawStageRow(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  stage: StageData,
  labels: SquareInfographicLabels,
): void {
  // Coloured difficulty pill (left)
  const pillW = 6;
  ctx.fillStyle =
    DIFFICULTY_HEX[getDifficulty(stage.distance, stage.elevation)] ??
    COLORS.brand;
  roundRect(ctx, x, y + 6, pillW, h - 12, 3);
  ctx.fill();

  // Label
  const dayLabel = `J${stage.dayNumber}`;
  const labelText = stage.label?.trim() || labels.restDay;
  const fullLabel = `${dayLabel} · ${labelText}`;
  // Right-side metrics first, to know how much space remains
  const km = `${Math.round(stage.distance)} km`;
  const elev = `${Math.round(stage.elevation)} m D+`;
  const right = `${km} · ${elev}`;
  ctx.font = `500 13px "Inter Tight", system-ui, -apple-system, sans-serif`;
  ctx.fillStyle = COLORS.inkSoft;
  const rightWidth = ctx.measureText(right).width;
  ctx.fillText(right, x + w - rightWidth, y + 8);

  ctx.fillStyle = COLORS.ink;
  ctx.font = `600 15px "Inter Tight", system-ui, -apple-system, sans-serif`;
  const maxLabelW = w - rightWidth - 24 - pillW;
  ctx.fillText(truncateText(ctx, fullLabel, maxLabelW), x + pillW + 12, y + 6);
}

// ───────────────────────────────────────────────────────────────────────────
// Route map (OSM tiles)
// ───────────────────────────────────────────────────────────────────────────

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

function chooseZoom(
  minLat: number,
  maxLat: number,
  minLon: number,
  maxLon: number,
  maxTiles: number,
): number {
  for (let z = 14; z >= 1; z--) {
    const tMin = lonLatToTile(minLon, maxLat, z);
    const tMax = lonLatToTile(maxLon, minLat, z);
    const numX = Math.max(1, tMax.x - tMin.x + 1);
    const numY = Math.max(1, tMax.y - tMin.y + 1);
    if (numX * numY <= maxTiles) return z;
  }
  return 4;
}

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

async function drawSquareRouteMap(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  stages: StageData[],
): Promise<void> {
  // Card background + rounded clip
  ctx.fillStyle = COLORS.mapFallback;
  roundRect(ctx, x, y, w, h, 16);
  ctx.fill();

  ctx.strokeStyle = COLORS.border;
  ctx.lineWidth = 1;
  roundRect(ctx, x + 0.5, y + 0.5, w - 1, h - 1, 16);
  ctx.stroke();

  const activeStages = stages.filter(
    (s) => !s.isRestDay && s.geometry.length >= 2,
  );
  if (activeStages.length === 0) return;

  const allPoints = activeStages.flatMap((s) => s.geometry);
  if (allPoints.length < 2) return;

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

  // 540×540 area can fit slightly more tiles than the landscape variant
  const zoom = chooseZoom(minLat, maxLat, minLon, maxLon, 16);
  const power = Math.pow(2, zoom);

  const toAbsPx = (lon: number, lat: number): [number, number] => {
    const px = ((lon + 180) / 360) * 256 * power;
    const latRad = (lat * Math.PI) / 180;
    const py =
      ((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) *
      256 *
      power;
    return [px, py];
  };

  const [bboxMinPxX, bboxMinPxY] = toAbsPx(minLon, maxLat);
  const [bboxMaxPxX, bboxMaxPxY] = toAbsPx(maxLon, minLat);
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

  const tileMin = lonLatToTile(minLon, maxLat, zoom);
  const tileMax = lonLatToTile(maxLon, minLat, zoom);

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

  ctx.save();
  roundRect(ctx, x, y, w, h, 16);
  ctx.clip();

  const tiles = await Promise.all(tilePromises);
  for (const { tx, ty, img } of tiles) {
    if (img) {
      const tileLon = (tx / Math.pow(2, zoom)) * 360 - 180;
      const tileLatRad = Math.atan(
        Math.sinh(Math.PI * (1 - (2 * ty) / Math.pow(2, zoom))),
      );
      const tileLat = (tileLatRad * 180) / Math.PI;
      const [cx, cy] = toCanvas(tileLon, tileLat);
      ctx.drawImage(img, cx, cy, 256 * scale, 256 * scale);
    }
  }

  // Draw route on top
  for (const stage of activeStages) {
    const color =
      DIFFICULTY_HEX[getDifficulty(stage.distance, stage.elevation)] ??
      COLORS.brand;
    // White halo for legibility on busy tiles
    ctx.strokeStyle = "rgba(255,255,255,0.85)";
    ctx.lineWidth = 8;
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

    ctx.strokeStyle = color;
    ctx.lineWidth = 5;
    ctx.beginPath();
    ctx.moveTo(sx, sy);
    for (let i = 1; i < stage.geometry.length; i++) {
      const pt = stage.geometry[i]!;
      const [px, py] = toCanvas(pt.lon, pt.lat);
      ctx.lineTo(px, py);
    }
    ctx.stroke();
  }

  // Start (green) + end (red) markers
  const firstGeom = activeStages[0]!.geometry;
  const [startX, startY] = toCanvas(firstGeom[0]!.lon, firstGeom[0]!.lat);
  drawMarker(ctx, startX, startY, "#22c55e");

  const lastGeom = activeStages[activeStages.length - 1]!.geometry;
  const lastPt = lastGeom[lastGeom.length - 1]!;
  const [endX, endY] = toCanvas(lastPt.lon, lastPt.lat);
  drawMarker(ctx, endX, endY, "#ef4444");

  ctx.restore();
}

function drawMarker(
  ctx: CanvasRenderingContext2D,
  cx: number,
  cy: number,
  color: string,
): void {
  ctx.fillStyle = "#ffffff";
  ctx.beginPath();
  ctx.arc(cx, cy, 10, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = color;
  ctx.beginPath();
  ctx.arc(cx, cy, 7, 0, Math.PI * 2);
  ctx.fill();
}

// ───────────────────────────────────────────────────────────────────────────
// Canvas utilities
// ───────────────────────────────────────────────────────────────────────────

function roundRect(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number,
): void {
  const rr = Math.min(r, w / 2, h / 2);
  ctx.beginPath();
  ctx.moveTo(x + rr, y);
  ctx.lineTo(x + w - rr, y);
  ctx.quadraticCurveTo(x + w, y, x + w, y + rr);
  ctx.lineTo(x + w, y + h - rr);
  ctx.quadraticCurveTo(x + w, y + h, x + w - rr, y + h);
  ctx.lineTo(x + rr, y + h);
  ctx.quadraticCurveTo(x, y + h, x, y + h - rr);
  ctx.lineTo(x, y + rr);
  ctx.quadraticCurveTo(x, y, x + rr, y);
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
    ctx.measureText(truncated + "…").width > maxWidth
  ) {
    truncated = truncated.slice(0, -1);
  }
  return truncated + "…";
}

/** Word-wrap `text` to at most `maxLines` lines fitting within `maxWidth`.
 * Overflow on the final line is collapsed and ellipsis-truncated. */
function wrapText(
  ctx: CanvasRenderingContext2D,
  text: string,
  maxWidth: number,
  maxLines: number,
): string[] {
  const words = text.split(/\s+/).filter(Boolean);
  if (words.length === 0) return [text];
  const lines: string[] = [];
  let current = "";
  let i = 0;
  while (i < words.length && lines.length < maxLines) {
    const word = words[i]!;
    const candidate = current ? `${current} ${word}` : word;
    if (ctx.measureText(candidate).width > maxWidth && current) {
      lines.push(current);
      current = "";
      // Don't consume `word`: re-evaluate it on the next line.
      continue;
    }
    current = candidate;
    i++;
  }
  if (lines.length < maxLines && current) {
    lines.push(current);
  } else if (i < words.length) {
    // We hit the maxLines budget mid-word; pack the remainder onto the
    // last line, truncated with an ellipsis if needed.
    const remaining = [current, ...words.slice(i)].filter(Boolean).join(" ");
    lines[lines.length - 1] = truncateText(ctx, remaining, maxWidth);
  }
  return lines;
}

/**
 * Trigger a browser download of the canvas content as a PNG file.
 */
export function downloadSquareInfographicPng(
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
