"use client";

import { useCallback, useState } from "react";

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

function readStoredMode(): TileMode {
  if (typeof window === "undefined") return DEFAULT_TILE_MODE;
  try {
    const stored = localStorage.getItem(TILE_MODE_STORAGE_KEY);
    return isTileMode(stored) ? stored : DEFAULT_TILE_MODE;
  } catch {
    return DEFAULT_TILE_MODE;
  }
}

/**
 * Reads / persists the user's preferred basemap mode in `localStorage`.
 *
 * Uses a lazy `useState` initializer so the persisted value is read once on
 * the first client render without triggering an extra re-render.
 * SSR: `typeof window === 'undefined'` guard returns the default, avoiding
 * hydration mismatches.
 */
export function useTileMode(): {
  tileMode: TileMode;
  setTileMode: (mode: TileMode) => void;
} {
  const [tileMode, setTileModeState] = useState<TileMode>(readStoredMode);

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
