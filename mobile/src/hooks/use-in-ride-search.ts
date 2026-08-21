import { useCallback, useRef, useState } from 'react';
import {
  MAX_RADIUS_METERS,
  searchNearbyPois,
  type InRidePoiCategory,
  type NearbyPoiSearchResult,
  type NearbyPoiSuggestion,
} from '../api/nearby-pois';

export { MAX_RADIUS_METERS };

/** Localized error message key (under `trip.inRide`) surfaced in the panel. */
export type InRideErrorKey = 'errorNetwork' | 'errorRateLimit' | 'errorGeneric';

/** A rider position, as the search body expects it (WGS84 lat/lon). */
export interface SearchPosition {
  lat: number;
  lon: number;
}

/** The last search outcome the panel renders (recap line + POI cards). */
export interface InRideRecap {
  category: InRidePoiCategory;
  radiusMeters: number;
  totalFound: number;
  capReached: boolean;
  outOfCoverage: boolean;
  pois: NearbyPoiSuggestion[];
}

export interface UseInRideSearch {
  /** A search round-trip is in flight. */
  isSearching: boolean;
  /** Localized error key to render, or null. */
  errorKey: InRideErrorKey | null;
  /** The latest recap, or null before the first search. */
  recap: InRideRecap | null;
  /** The category of the in-flight / latest search (for chip highlighting). */
  activeCategory: InRidePoiCategory | null;
  /** Whether the latest recap can still be widened (not capped, radius < max). */
  canWiden: boolean;
  /** Run a search for the tapped intent from the current position. */
  search: (category: InRidePoiCategory, position: SearchPosition) => void;
  /** Replay the last search with a doubled radius (bounded by MAX_RADIUS_METERS). */
  widen: (position: SearchPosition) => void;
}

function errorKeyFor(
  status: Exclude<NearbyPoiSearchResult['status'], 'ok'>,
): InRideErrorKey {
  switch (status) {
    case 'network':
      return 'errorNetwork';
    case 'rate_limited':
      return 'errorRateLimit';
    default:
      return 'errorGeneric';
  }
}

/**
 * Orchestrates the guided in-ride search (ADR-048) on mobile — mirror of the web
 * `useInRideSearch`. The `POST /trips/{id}/nearby-pois` round-trip, error
 * classification (network / 429 rate-limit / generic) and the radius-doubling
 * "widen" replay. Position comes from the #1149 foreground GPS hook and is passed
 * in per call, so a moving rider always searches from their current fix.
 *
 * A monotonic sequence guard drops a stale response that resolves after a newer
 * search has started, so tapping several intents in a row never renders an
 * out-of-order recap.
 */
export function useInRideSearch(
  tripId: string,
  stageDay?: number | null,
): UseInRideSearch {
  const [isSearching, setIsSearching] = useState(false);
  const [errorKey, setErrorKey] = useState<InRideErrorKey | null>(null);
  const [recap, setRecap] = useState<InRideRecap | null>(null);
  const [activeCategory, setActiveCategory] = useState<InRidePoiCategory | null>(
    null,
  );
  const seqRef = useRef(0);

  const run = useCallback(
    async (
      category: InRidePoiCategory,
      position: SearchPosition,
      radiusMeters?: number,
    ) => {
      const seq = (seqRef.current += 1);
      setActiveCategory(category);
      setIsSearching(true);
      setErrorKey(null);
      const result = await searchNearbyPois(tripId, {
        category,
        position,
        radiusMeters: radiusMeters ?? null,
        stageDay: stageDay ?? null,
      });
      // A newer search has started — drop this superseded response entirely.
      if (seq !== seqRef.current) {
        return;
      }
      setIsSearching(false);
      if (result.status !== 'ok') {
        setErrorKey(errorKeyFor(result.status));
        return;
      }
      const data = result.data;
      setRecap({
        category,
        radiusMeters: data.radiusMeters ?? radiusMeters ?? 0,
        totalFound: data.totalFound ?? 0,
        capReached: data.capReached ?? false,
        outOfCoverage: data.outOfCoverage ?? false,
        pois: data.pois ?? [],
      });
    },
    [tripId, stageDay],
  );

  const search = useCallback(
    (category: InRidePoiCategory, position: SearchPosition) => {
      void run(category, position);
    },
    [run],
  );

  const widen = useCallback(
    (position: SearchPosition) => {
      if (!recap || recap.capReached) {
        return;
      }
      const next = Math.min(recap.radiusMeters * 2, MAX_RADIUS_METERS);
      // Already at the ceiling — widening is a no-op, so abandon (ADR-048 §9).
      if (next <= recap.radiusMeters) {
        return;
      }
      void run(recap.category, position, next);
    },
    [recap, run],
  );

  const canWiden =
    !isSearching &&
    recap !== null &&
    !recap.capReached &&
    recap.radiusMeters < MAX_RADIUS_METERS;

  return {
    isSearching,
    errorKey,
    recap,
    activeCategory,
    canWiden,
    search,
    widen,
  };
}
