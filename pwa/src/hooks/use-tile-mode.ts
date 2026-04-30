"use client";

import { useCallback, useEffect, useState } from "react";

/**
 * Available basemap tile modes for the trip map.
 *
 * - `map`       — OpenStreetMap-derived vector style (Carto positron / dark-matter)
 * - `satellite` — Esri WorldImagery raster tiles
 */
export type TileMode = "map" | "satellite";

const TILE_MODE_STORAGE_KEY = "bike-trip-planner:map.tileMode";
const DEFAULT_TILE_MODE: TileMode = "map";

function isTileMode(value: unknown): value is TileMode {
  return value === "map" || value === "satellite";
}

/**
 * Reads / persists the user's preferred basemap mode in `localStorage`.
 *
 * Initialises to `DEFAULT_TILE_MODE` on both server and client (identical
 * initial renders, no hydration mismatch). A mount-only `useEffect` then reads
 * the persisted value and updates the pill selection client-side.
 */
export function useTileMode(): {
  tileMode: TileMode;
  setTileMode: (mode: TileMode) => void;
} {
  const [tileMode, setTileModeState] = useState<TileMode>(DEFAULT_TILE_MODE);

  // One-time mount effect to read persisted preference. setState after mount is
  // the standard Next.js pattern for localStorage-backed state (the extra
  // render updates the pill after hydration and is intentional).
  useEffect(() => {
    try {
      const stored = localStorage.getItem(TILE_MODE_STORAGE_KEY);
      // eslint-disable-next-line react-hooks/set-state-in-effect
      if (isTileMode(stored)) setTileModeState(stored);
    } catch {
      // localStorage may be unavailable (e.g. strict privacy mode) — keep default.
    }
  }, []);

  const setTileMode = useCallback((newMode: TileMode) => {
    setTileModeState(newMode);
    try {
      localStorage.setItem(TILE_MODE_STORAGE_KEY, newMode);
    } catch {
      // Ignore write failures — the in-memory state still updates.
    }
  }, []);

  return { tileMode, setTileMode };
}
