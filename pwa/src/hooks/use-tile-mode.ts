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
 * The value stays `null` during SSR and the very first client render so we
 * never trigger a hydration mismatch. Once resolved on the client, callers
 * receive the persisted choice (or `DEFAULT_TILE_MODE` on first visit).
 */
export function useTileMode(): {
  tileMode: TileMode;
  setTileMode: (mode: TileMode) => void;
  ready: boolean;
} {
  // Always start from the default during SSR and the very first client render
  // so React hydration sees identical markup. The persisted value is applied
  // in the effect below; the map's setStyle effect picks up the change.
  const [tileMode, setTileModeState] = useState<TileMode>(DEFAULT_TILE_MODE);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    try {
      const stored = localStorage.getItem(TILE_MODE_STORAGE_KEY);
      if (isTileMode(stored)) {
        setTileModeState(stored);
      }
    } catch {
      // localStorage may be unavailable (e.g. strict privacy mode) — fall back
      // to the default and skip persistence.
    }
    setReady(true);
  }, []);

  const setTileMode = useCallback((mode: TileMode) => {
    setTileModeState(mode);
    try {
      localStorage.setItem(TILE_MODE_STORAGE_KEY, mode);
    } catch {
      // Ignore write failures — the in-memory state still updates.
    }
  }, []);

  return { tileMode, setTileMode, ready };
}
