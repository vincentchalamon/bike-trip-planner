"use client";

import { useCallback, useEffect, useState } from "react";
import {
  searchNearbyPois,
  type InRidePoiCategory,
  type NearbyPoiSearchResult,
} from "@/lib/api/client";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { useGeolocation } from "@/hooks/use-geolocation";

/**
 * Hard ceiling on the search radius (mirrors
 * `App\InRide\InRidePoiCategory::MAX_RADIUS_METERS`). The "widen" affordance
 * doubles the last effective radius but never pushes past this bound.
 */
export const MAX_RADIUS_METERS = 20_000;

/** Localized error message key (under `chat.inRide`) surfaced in the panel. */
export type InRideErrorKey = "errorNetwork" | "errorRateLimit" | "errorGeneric";

export interface UseInRideSearchResult {
  /** A search (geolocation lookup and/or API call) is in flight. */
  isSearching: boolean;
  /** Localized error key to render, or null. */
  errorKey: InRideErrorKey | null;
  /** Whether to surface the "share my location" prompt (geoloc denied/blocked). */
  geolocPromptVisible: boolean;
  /** Whether the latest recap can still be widened (not capped, radius < max). */
  canWiden: boolean;
  /** Run a search for the tapped category chip. */
  search: (category: InRidePoiCategory) => void;
  /** Replay the last search with a doubled radius (bounded by MAX_RADIUS_METERS). */
  widen: () => void;
  /** Retry the geolocation lookup (from the prompt). */
  requestGeoloc: () => void;
}

interface LastSearch {
  category: InRidePoiCategory;
  radiusMeters: number;
  capReached: boolean;
}

function errorKeyFor(status: NearbyPoiSearchResult["status"]): InRideErrorKey {
  switch (status) {
    case "network":
      return "errorNetwork";
    case "rate_limited":
      return "errorRateLimit";
    default:
      return "errorGeneric";
  }
}

/**
 * Orchestrates the guided in-ride search (#935): one-shot geolocation, the
 * `POST /trips/{id}/nearby-pois` round-trip, error classification and the
 * radius-doubling "widen" replay. Appends the resulting turns to the in-memory
 * in-ride thread so the panel stays a thin renderer.
 */
export function useInRideSearch(): UseInRideSearchResult {
  const tripId = useTripStore((s) => s.trip?.id ?? null);
  const activeDayNumber = useUiStore((s) => s.activeDayNumber);
  const appendMessage = useUiStore((s) => s.appendMessage);

  const geo = useGeolocation();

  const [apiInFlight, setApiInFlight] = useState(false);
  const [errorKey, setErrorKey] = useState<InRideErrorKey | null>(null);
  // The category awaiting a fresh geolocation fix before it can be searched.
  const [pendingCategory, setPendingCategory] =
    useState<InRidePoiCategory | null>(null);
  const [lastSearch, setLastSearch] = useState<LastSearch | null>(null);

  const runSearch = useCallback(
    async (
      category: InRidePoiCategory,
      position: { lat: number; lon: number },
      radiusMeters?: number,
    ) => {
      if (!tripId) return;
      setApiInFlight(true);
      setErrorKey(null);
      const result = await searchNearbyPois(tripId, {
        category,
        position,
        radiusMeters: radiusMeters ?? null,
        stageDay: activeDayNumber,
      });
      setApiInFlight(false);
      if (result.status !== "ok") {
        setErrorKey(errorKeyFor(result.status));
        return;
      }
      const { data } = result;
      appendMessage({
        role: "assistant",
        kind: "recap",
        ts: Date.now(),
        category,
        radiusMeters: data.radiusMeters,
        totalFound: data.totalFound,
        capReached: data.capReached,
        outOfCoverage: data.outOfCoverage,
        pois: data.pois,
      });
      setLastSearch({
        category,
        radiusMeters: data.radiusMeters,
        capReached: data.capReached,
      });
    },
    [tripId, activeDayNumber, appendMessage],
  );

  // Once the pending geolocation fix lands, fire the deferred search.
  useEffect(() => {
    if (pendingCategory === null || !geo.position) return;
    const category = pendingCategory;
    setPendingCategory(null);
    void runSearch(category, {
      lat: geo.position.latitude,
      lon: geo.position.longitude,
    });
  }, [pendingCategory, geo.position, runSearch]);

  // A geolocation failure clears the pending intent; the prompt takes over.
  useEffect(() => {
    if (geo.error && pendingCategory !== null) {
      setPendingCategory(null);
    }
  }, [geo.error, pendingCategory]);

  const search = useCallback(
    (category: InRidePoiCategory) => {
      setErrorKey(null);
      appendMessage({
        role: "user",
        kind: "question",
        ts: Date.now(),
        category,
      });
      if (geo.position) {
        void runSearch(category, {
          lat: geo.position.latitude,
          lon: geo.position.longitude,
        });
        return;
      }
      setPendingCategory(category);
      geo.request();
    },
    [appendMessage, geo, runSearch],
  );

  const widen = useCallback(() => {
    if (!lastSearch || lastSearch.capReached || !geo.position) return;
    const next = Math.min(lastSearch.radiusMeters * 2, MAX_RADIUS_METERS);
    if (next <= lastSearch.radiusMeters) return;
    void runSearch(
      lastSearch.category,
      { lat: geo.position.latitude, lon: geo.position.longitude },
      next,
    );
  }, [lastSearch, geo.position, runSearch]);

  const requestGeoloc = useCallback(() => {
    geo.request();
  }, [geo]);

  const isSearching =
    apiInFlight || (pendingCategory !== null && geo.isRequesting);

  const canWiden =
    !isSearching &&
    lastSearch !== null &&
    !lastSearch.capReached &&
    lastSearch.radiusMeters < MAX_RADIUS_METERS;

  const geolocPromptVisible = !geo.position && geo.error !== null;

  return {
    isSearching,
    errorKey,
    geolocPromptVisible,
    canWiden,
    search,
    widen,
    requestGeoloc,
  };
}
