"use client";

import { useEffect, useRef } from "react";
import { MercureClient } from "@/lib/mercure/client";
import type { EnrichedStagePayload, MercureEvent } from "@/lib/mercure/types";
import { TripAiOverviewSchema } from "@/lib/validation/schemas";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { reverseGeocode } from "@/lib/geocode/client";
import { toast } from "@/components/ui/sonner";
import { DEFAULT_ACCOMMODATION_RADIUS_KM } from "@/lib/accommodation-constants";
import type { StageData } from "@/lib/validation/schemas";

/**
 * The Mercure hub the browser subscribes to. An explicit
 * `NEXT_PUBLIC_MERCURE_URL` wins (the native Capacitor build targets its remote
 * hub); otherwise the hub is taken from the CURRENT origin, so the web app works
 * unchanged on https://localhost, in prod (same origin), and behind a tunnel
 * (ngrok) for mobile testing — without baking a URL into the bundle. The
 * localhost fallback only applies during SSR, where no EventSource is opened.
 */
function resolveMercureHubUrl(): string {
  if (process.env.NEXT_PUBLIC_MERCURE_URL) {
    return process.env.NEXT_PUBLIC_MERCURE_URL;
  }
  if (typeof window !== "undefined") {
    return `${window.location.origin}/.well-known/mercure`;
  }
  return "https://localhost/.well-known/mercure";
}

const stageDiffTimers = new Map<number, ReturnType<typeof setTimeout>>();

/**
 * Dispatches a Mercure SSE event to the appropriate Zustand store action.
 *
 * Acts as the central event router for all server-pushed computation results.
 * Each event type maps to one or more store mutations in {@link useTripStore}
 * or {@link useUiStore}. For `stages_computed` events, performs smart merging:
 * partial updates only reset derived data (weather, POIs, labels) for affected
 * stages, while full replacements preserve data for stages whose endpoints
 * did not move. Stage labels are resolved asynchronously via reverse geocoding.
 *
 * Event types handled:
 * - `route_parsed` — initial route metadata (distance, elevation, source)
 * - `stages_computed` — stage geometry and pacing (partial or full, legacy)
 * - `weather_fetched` — per-stage weather forecasts
 * - `pois_scanned` — points of interest with optional alerts
 * - `accommodations_found` — accommodation options per stage
 * - `events_found` — DataTourisme dated events per stage
 * - `supply_timeline` — clustered supply markers per stage (water + food POIs)
 * - `terrain_alerts` / `calendar_alerts` / `wind_alerts` / `bike_shop_alerts` / `water_point_alerts` / `railway_station_alerts` / `health_service_alerts` / `border_crossing_alerts` / `ferry_alerts` / `ford_alerts` — alert categories
 * - `computation_step_completed` — Mode 1 progress tick (drives progress bar)
 * - `trip_ready` — Mode 1 atomic enriched payload (final analysis swap)
 * - `stage_updated` — Mode 2 per-stage update (inline modifications)
 * - `trip_complete` — final computation status, stops processing spinner (legacy)
 * - `validation_error` / `computation_error` — error toasts and recovery
 */
function dispatchEvent(event: MercureEvent): void {
  const store = useTripStore.getState();

  switch (event.type) {
    case "route_parsed":
      store.updateRouteData(event.data);
      break;

    case "stages_computed": {
      const { affectedIndices } = event.data;
      const existingStages = store.stages;

      if (
        affectedIndices &&
        affectedIndices.length > 0 &&
        existingStages.length > 0
      ) {
        // Partial update: merge only affected stages, preserve unaffected
        const affected = new Set(affectedIndices);
        const merged = event.data.stages.map((s, i) => {
          const existing = existingStages[i];
          if (existing && !affected.has(i)) {
            // Preserve derived data for unaffected stages, update core fields
            return {
              ...existing,
              dayNumber: s.dayNumber,
              distance: s.distance,
              elevation: s.elevation,
              elevationLoss: s.elevationLoss ?? 0,
              startPoint: s.startPoint,
              endPoint: s.endPoint,
              geometry: s.geometry ?? existing.geometry,
              label: s.label ?? existing.label,
            };
          }
          // Reset derived data for affected or new stages,
          // but preserve accommodations: if a new scan is needed,
          // accommodations_found will arrive and replace them
          return {
            ...s,
            elevationLoss: s.elevationLoss ?? 0,
            geometry: s.geometry ?? [],
            label: s.label ?? null,
            isRestDay: s.isRestDay ?? false,
            startLabel: null,
            endLabel: null,
            weather: null,
            // Preserve the existing alerts (like the accommodations below) until
            // the recompute's fresh per-category alert events replace them, so a
            // stage's alerts don't flash empty between `stages_computed` and those
            // follow-up events (e.g. after selecting an accommodation) (recette #649).
            alerts: existing?.alerts ?? [],
            pois: [],
            supplyTimeline: [],
            events: [],
            accommodations: existing?.accommodations ?? [],
            selectedAccommodation: existing?.selectedAccommodation ?? null,
            accommodationSearchRadiusKm:
              existing?.accommodationSearchRadiusKm ??
              DEFAULT_ACCOMMODATION_RADIUS_KM,
          };
        });
        store.setStages(merged);
        // Only resolve labels for affected stages
        const affectedStages = merged.filter((_, i) => affected.has(i));
        if (affectedStages.length > 0) {
          resolveStageLabels(affectedStages, affectedIndices);
        }
      } else {
        // Full replace: initial generation or no affectedIndices
        // Preserve accommodations/labels for stages whose endpoints didn't move
        const stages = event.data.stages.map((s, i) => {
          const prev = existingStages[i];
          const endMatch =
            prev &&
            prev.endPoint.lat === s.endPoint.lat &&
            prev.endPoint.lon === s.endPoint.lon;
          const startMatch =
            prev &&
            prev.startPoint.lat === s.startPoint.lat &&
            prev.startPoint.lon === s.startPoint.lon;

          return {
            ...s,
            elevationLoss: s.elevationLoss ?? 0,
            geometry: s.geometry ?? [],
            label: s.label ?? null,
            isRestDay: s.isRestDay ?? false,
            startLabel: startMatch ? prev.startLabel : null,
            endLabel: endMatch ? prev.endLabel : null,
            weather: null,
            alerts: [],
            pois: [],
            supplyTimeline: [],
            events: [],
            accommodations: endMatch ? prev.accommodations : [],
            accommodationSearchRadiusKm: endMatch
              ? (prev.accommodationSearchRadiusKm ??
                DEFAULT_ACCOMMODATION_RADIUS_KM)
              : DEFAULT_ACCOMMODATION_RADIUS_KM,
          };
        });
        store.setStages(stages);

        // Only resolve labels for stages that need them
        const needsLabels = stages
          .map((s, i) => ({ s, i }))
          .filter(({ s }) => s.startLabel === null || s.endLabel === null);
        if (needsLabels.length > 0) {
          resolveStageLabels(
            needsLabels.map(({ s }) => s),
            needsLabels.map(({ i }) => i),
          );
        }
      }
      break;
    }

    case "weather_fetched":
      for (const s of event.data.stages) {
        if (s.weather) {
          store.updateStageWeather(s.dayNumber, s.weather);
        }
      }
      // Weather enrichment landed — resolve its per-block spinner (ADR-043).
      useUiStore.getState().setBlockStatus("weather", "done");
      break;

    case "pois_scanned":
      store.updateStagePois(event.data.stageIndex, event.data.pois);
      if (event.data.alerts && event.data.alerts.length > 0) {
        store.updateStageAlerts(
          event.data.stageIndex,
          event.data.alerts,
          "pois",
        );
      }
      break;

    case "supply_timeline":
      store.updateStageSupplyTimeline(
        event.data.stageIndex,
        event.data.markers,
      );
      break;

    case "accommodations_found":
      store.updateStageAccommodations(
        event.data.stageIndex,
        event.data.accommodations,
        event.data.searchRadiusKm,
      );
      if (event.data.alerts && event.data.alerts.length > 0) {
        store.updateStageAlerts(
          event.data.stageIndex,
          event.data.alerts,
          "accommodations",
        );
      }
      // Settle the "Recherche d'hébergements" spinner as soon as results land.
      // A standalone scan (expand-radius / 409 re-scan) never emits a terminal
      // trip_ready/trip_complete, so without this the spinner spins forever even
      // though the accommodations are already shown (recette #649).
      useUiStore.getState().setAccommodationScanning(false);
      break;

    case "events_found":
      store.setStageEvents(event.data.stageIndex, event.data.events);
      break;

    case "terrain_alerts":
      for (const [indexStr, alerts] of Object.entries(
        event.data.alertsByStage,
      )) {
        const idx = Number(indexStr);
        if (!isNaN(idx)) {
          store.updateStageAlerts(idx, alerts, "terrain");
        }
      }
      break;

    case "calendar_alerts": {
      const calendarByStage = new Map<number, typeof event.data.nudges>();
      for (const nudge of event.data.nudges) {
        const existing = calendarByStage.get(nudge.stageIndex) ?? [];
        existing.push(nudge);
        calendarByStage.set(nudge.stageIndex, existing);
      }
      // The payload is a full replacement of the calendar nudges: clear the
      // group on EVERY stage first, otherwise a stage that dropped out of the
      // new set (e.g. no longer a Sunday after a rest-day insert) keeps its
      // stale nudge because the loop below only visits stages present in the
      // payload (recette bug: "L'étape 3 tombe un dimanche" on a Tuesday).
      for (let i = 0; i < store.stages.length; i++) {
        store.updateStageAlerts(i, [], "calendar");
      }
      for (const [stageIndex, nudges] of calendarByStage) {
        store.updateStageAlerts(
          stageIndex,
          nudges.map((n) => ({
            type: "nudge" as const,
            message: n.message,
            lat: null,
            lon: null,
          })),
          "calendar",
        );
      }
      break;
    }

    case "wind_alerts":
      store.updateStageAlerts(0, event.data.alerts, "wind");
      break;

    case "bike_shop_alerts": {
      const bikeShopByStage = new Map<number, typeof event.data.alerts>();
      for (const alert of event.data.alerts) {
        const existing = bikeShopByStage.get(alert.stageIndex) ?? [];
        existing.push(alert);
        bikeShopByStage.set(alert.stageIndex, existing);
      }
      for (const [stageIndex, alerts] of bikeShopByStage) {
        store.updateStageAlerts(
          stageIndex,
          alerts.map((a) => ({
            type: a.type as "nudge",
            message: a.message,
            lat: null,
            lon: null,
          })),
          "bike_shop",
        );
      }
      break;
    }

    case "water_point_alerts": {
      // Note: event.data.waterPointsByStage is available but not yet consumed — reserved for future map layer display
      const waterByStage = new Map<number, typeof event.data.alerts>();
      for (const alert of event.data.alerts) {
        const existing = waterByStage.get(alert.stageIndex) ?? [];
        existing.push(alert);
        waterByStage.set(alert.stageIndex, existing);
      }
      for (const [stageIndex, alerts] of waterByStage) {
        store.updateStageAlerts(
          stageIndex,
          alerts.map((a) => ({
            type: a.type as "nudge",
            message: a.message,
            lat: null,
            lon: null,
            source: "water_point",
          })),
          "water_point",
        );
      }
      break;
    }

    case "health_service_alerts": {
      const healthByStage = new Map<number, typeof event.data.alerts>();
      for (const alert of event.data.alerts) {
        const existing = healthByStage.get(alert.stageIndex) ?? [];
        existing.push(alert);
        healthByStage.set(alert.stageIndex, existing);
      }
      for (const [stageIndex, alerts] of healthByStage) {
        store.updateStageAlerts(
          stageIndex,
          alerts.map((a) => ({
            type: a.type as "nudge",
            message: a.message,
          })),
          "health_service",
        );
      }
      break;
    }

    case "cultural_poi_alerts": {
      const culturalPoiByStage = new Map<number, typeof event.data.alerts>();
      for (const alert of event.data.alerts) {
        const existing = culturalPoiByStage.get(alert.stageIndex) ?? [];
        existing.push(alert);
        culturalPoiByStage.set(alert.stageIndex, existing);
      }
      for (const [stageIndex, alerts] of culturalPoiByStage) {
        store.updateStageAlerts(
          stageIndex,
          alerts.map((a) => ({
            type: "nudge" as const,
            message: a.message,
            lat: a.lat,
            lon: a.lon,
            source: "cultural_poi",
            poiName: a.poiName,
            poiType: a.poiType,
            poiLat: a.poiLat,
            poiLon: a.poiLon,
            distanceFromRoute: a.distanceFromRoute,
            // Enrichment fields (Wikidata / DataTourisme) — forwarded so the
            // map popover can render variant A (issue #398).
            description: a.description,
            openingHours: a.openingHours,
            estimatedPrice: a.estimatedPrice,
            imageUrl: a.imageUrl,
            wikidataId: a.wikidataId,
            wikipediaUrl: a.wikipediaUrl,
          })),
          "cultural_poi",
        );
      }
      break;
    }

    case "railway_station_alerts": {
      const railwayByStage = new Map<number, typeof event.data.alerts>();
      for (const alert of event.data.alerts) {
        const existing = railwayByStage.get(alert.stageIndex) ?? [];
        existing.push(alert);
        railwayByStage.set(alert.stageIndex, existing);
      }
      for (const [stageIndex, alerts] of railwayByStage) {
        store.updateStageAlerts(
          stageIndex,
          alerts.map((a) => ({
            type: "nudge" as const,
            message: a.message,
            lat: a.actionLat ?? null,
            lon: a.actionLon ?? null,
            source: "railway_station",
            ...(a.action === "navigate" &&
            a.actionLat != null &&
            a.actionLon != null
              ? {
                  action: {
                    kind: "navigate" as const,
                    label: "Navigate to station",
                    payload: { lat: a.actionLat, lon: a.actionLon },
                  },
                }
              : {}),
          })),
          "railway_station",
        );
      }
      break;
    }

    case "border_crossing_alerts": {
      const borderByStage = new Map<number, typeof event.data.alerts>();
      for (const alert of event.data.alerts) {
        const existing = borderByStage.get(alert.stageIndex) ?? [];
        existing.push(alert);
        borderByStage.set(alert.stageIndex, existing);
      }
      for (const [stageIndex, alerts] of borderByStage) {
        store.updateStageAlerts(
          stageIndex,
          alerts.map((a) => ({
            type: a.type,
            message: a.message,
            lat: a.lat,
            lon: a.lon,
            source: "border_crossing",
            action: {
              kind: "navigate" as const,
              label: "Navigate to crossing",
              payload: { lat: a.lat, lon: a.lon },
            },
          })),
          "border_crossing",
        );
      }
      break;
    }

    case "ferry_alerts": {
      const ferryByStage = new Map<number, typeof event.data.alerts>();
      for (const alert of event.data.alerts) {
        const existing = ferryByStage.get(alert.stageIndex) ?? [];
        existing.push(alert);
        ferryByStage.set(alert.stageIndex, existing);
      }
      for (const [stageIndex, alerts] of ferryByStage) {
        store.updateStageAlerts(
          stageIndex,
          alerts.map((a) => ({
            type: a.type,
            message: a.message,
            lat: a.lat,
            lon: a.lon,
            source: "ferry",
            action: {
              kind: a.action.kind,
              label: a.action.label,
              payload: a.action.payload,
            },
          })),
          "ferry",
        );
      }
      break;
    }

    case "ford_alerts": {
      const fordByStage = new Map<number, typeof event.data.alerts>();
      for (const alert of event.data.alerts) {
        const existing = fordByStage.get(alert.stageIndex) ?? [];
        existing.push(alert);
        fordByStage.set(alert.stageIndex, existing);
      }
      for (const [stageIndex, alerts] of fordByStage) {
        store.updateStageAlerts(
          stageIndex,
          alerts.map((a) => ({
            type: a.type,
            message: a.message,
            lat: a.lat,
            lon: a.lon,
            source: "ford",
            action: {
              kind: a.action.kind,
              label: a.action.label,
              payload: a.action.payload,
            },
          })),
          "ford",
        );
      }
      break;
    }

    case "route_segment_recalculated": {
      store.updateStageAfterRouteRecalculation(event.data.stageIndex, {
        distance: event.data.distance,
        elevationGain: event.data.elevationGain,
        coordinates: event.data.coordinates,
      });
      store.updateStageAlerts(event.data.stageIndex, [], "cultural_poi");
      store.clearRecomputingStages();
      useUiStore.getState().setProcessing(false);
      break;
    }

    case "trip_complete":
      store.setComputationStatus(event.data.computationStatus);
      // Terminal completion — settle the global processing/scanning overlays
      // and the per-block enrichment spinners (ADR-043). Weather/AI are marked
      // done here as a safety net in case their dedicated events (or the
      // detail-status hydration) did not flip them.
      useUiStore.getState().setBlockStatus("weather", "done");
      useUiStore.getState().setBlockStatus("ai", "done");
      store.clearRecomputingStages();
      useUiStore.getState().setProcessing(false);
      useUiStore.getState().setAccommodationScanning(false);
      break;

    case "computation_step_completed":
      // Mode 1 — progress tick only. With the synchronous structural flow
      // there is no narrative progress screen to drive (ADR-043); the tick is
      // a no-op kept for wire-compatibility with the existing backend events.
      break;

    case "trip_ready": {
      // Mode 1 — atomic swap of the whole trip. We intentionally replace the
      // stage array in a single mutation (instead of the legacy 10+ partial
      // events) to avoid the cumulative layout shift observed in Acte 2.
      const incomingStages = event.data.stages.map(enrichedPayloadToStageData);
      store.applyTripReady(incomingStages);
      store.setComputationStatus(event.data.computationStatus);
      // Trip-level AI overview (issue #305) — `aiOverview` is optional and
      // arrives from an LLM pipeline, so partial / malformed payloads must
      // not crash the renderer. Zod parses + coerces missing array fields to
      // `[]`; any parse failure collapses to `null` so the component falls
      // back silently rather than rendering a half-populated card.
      const parsedOverview = TripAiOverviewSchema.safeParse(
        event.data.aiOverview,
      );
      store.setAiOverview(parsedOverview.success ? parsedOverview.data : null);
      // A fresh full analysis just landed → the overview is in sync again
      // (clears the "outdated" banner without waiting for a reload).
      store.setAiOverviewStale(false);
      // The terminal enrichment payload landed — resolve both async block
      // spinners (ADR-043). Weather rides along in the atomic swap, so mark it
      // done too in case `weather_fetched` never fired separately.
      useUiStore.getState().setBlockStatus("ai", "done");
      useUiStore.getState().setBlockStatus("weather", "done");
      store.clearRecomputingStages();
      useUiStore.getState().setProcessing(false);
      useUiStore.getState().setAccommodationScanning(false);

      // Re-read the store to capture the freshly applied stages.
      const snapshotStore = useTripStore.getState();

      // Resolve labels for any stage that still lacks them.
      const needsLabels = snapshotStore.stages
        .map((s, i) => ({ s, i }))
        .filter(({ s }) => s.startLabel === null || s.endLabel === null);
      if (needsLabels.length > 0) {
        resolveStageLabels(
          needsLabels.map(({ s }) => s),
          needsLabels.map(({ i }) => i),
        );
      }
      break;
    }

    case "stage_updated": {
      // Mode 2 — per-stage update. Replace the single slice; preserved
      // fields (labels, search radius) are handled by the store.
      const incoming = enrichedPayloadToStageData(event.data.stage);

      // Capture previous state before applying the update to compute the diff.
      const prevStage = store.stages[event.data.stageIndex];
      store.applyStageUpdate(event.data.stageIndex, incoming);

      // Compute the set of changed fields for transient diff highlighting.
      if (prevStage) {
        const changed = computeStageDiff(prevStage, incoming);
        if (changed.size > 0) {
          const existingTimer = stageDiffTimers.get(event.data.stageIndex);
          if (existingTimer !== undefined) clearTimeout(existingTimer);
          store.setStageDiff(event.data.stageIndex, changed);
          const timer = setTimeout(() => {
            useTripStore.getState().clearStageDiff(event.data.stageIndex);
            stageDiffTimers.delete(event.data.stageIndex);
          }, 3000);
          stageDiffTimers.set(event.data.stageIndex, timer);
        }
      }

      // Remove this stage from the recomputing set — the shimmer skeleton
      // can now be replaced by the real card.
      store.finishStageRecomputation(event.data.stageIndex);

      // A batch/inline recompute (Mode 2) re-publishes per-stage `stage_updated`
      // events but never the terminal `trip_complete`/`trip_ready`: the enrichment
      // gate only fires once EVERY initialised computation has settled, and the
      // computations a trip never runs (e.g. weather/calendar without dates) stay
      // "pending" forever. So once the last recomputing stage settles, clear the
      // global processing overlay here — otherwise it spins forever (recette #649,
      // "profil expert"). Tied to `recomputingStages` so the initial full analysis
      // (which never populates that set) keeps relying on `trip_ready`.
      if (useTripStore.getState().recomputingStages.size === 0) {
        useUiStore.getState().setProcessing(false);
      }
      // Labels may have been wiped if endpoints moved — refresh if needed.
      const updated = useTripStore.getState().stages[event.data.stageIndex];
      if (
        updated &&
        (updated.startLabel === null || updated.endLabel === null)
      ) {
        resolveStageLabels([updated], [event.data.stageIndex]);
      }
      break;
    }

    case "validation_error":
      toast.error(event.data.message);
      store.clearRecomputingStages();
      useUiStore.getState().setProcessing(false);
      useUiStore.getState().setAccommodationScanning(false);
      break;

    case "computation_error": {
      toast.error(`Computation failed: ${event.data.message}`);
      // Map the failed computation onto its per-block spinner so the matching
      // block surfaces an error + retry affordance (ADR-043). Weather/wind →
      // weather; the LLM passes → ai. Other computations have no dedicated
      // block and only settle the global processing flag below.
      const computation = event.data.computation;
      if (computation === "weather" || computation === "wind") {
        useUiStore.getState().setBlockStatus("weather", "failed");
      } else if (
        computation === "stage_ai_analysis" ||
        computation === "trip_ai_overview"
      ) {
        useUiStore.getState().setBlockStatus("ai", "failed");
      }
      if (!event.data.retryable) {
        store.clearRecomputingStages();
        useUiStore.getState().setProcessing(false);
        useUiStore.getState().setAccommodationScanning(false);
      }
      break;
    }
  }
}

/**
 * Compares a previous and incoming stage snapshot and returns the set of
 * logical field names that have changed. Used to populate `stageDiffs` in
 * the store so that `DiffHighlight` can transiently highlight each changed
 * piece of data.
 *
 * Compared fields: `distance`, `alerts_added`.
 */
function computeStageDiff(prev: StageData, next: StageData): Set<string> {
  const changed = new Set<string>();

  if (prev.distance !== next.distance) changed.add("distance");

  // Alert changes: detect newly added alerts only
  const prevMessages = new Set(
    prev.alerts.map((a) => `${a.type}:${a.message}`),
  );
  const nextMessages = new Set(
    next.alerts.map((a) => `${a.type}:${a.message}`),
  );
  const hasNewAlerts = [...nextMessages].some((m) => !prevMessages.has(m));
  if (hasNewAlerts) changed.add("alerts_added");

  return changed;
}

/**
 * Converts an enriched stage wire payload (from `trip_ready` / `stage_updated`)
 * into a {@link StageData} usable by the Zustand store. Supplies defaults for
 * the client-only fields that the backend intentionally does not serialize
 * (reverse-geocoded labels, accommodation search radius).
 */
function enrichedPayloadToStageData(payload: EnrichedStagePayload): StageData {
  return {
    dayNumber: payload.dayNumber,
    distance: payload.distance,
    elevation: payload.elevation,
    elevationLoss: payload.elevationLoss,
    startPoint: payload.startPoint,
    endPoint: payload.endPoint,
    geometry: payload.geometry,
    label: payload.label,
    startLabel: null,
    endLabel: null,
    weather: payload.weather,
    // Tag with the producing group so a later terrain_alerts event REPLACES
    // (not duplicates) these. AnalyzeTerrain is the sole writer of the persisted
    // alerts column since #794 (recette #649 round 7, #2; mirrors the trip-page
    // hydrate). Covers stages_computed / trip_ready / stage_updated payloads.
    alerts: (payload.alerts ?? []).map((a) => ({ ...a, _group: "terrain" })),
    pois: payload.pois,
    accommodations: payload.accommodations,
    selectedAccommodation: payload.selectedAccommodation,
    accommodationSearchRadiusKm: DEFAULT_ACCOMMODATION_RADIUS_KM,
    isRestDay: payload.isRestDay ?? false,
    supplyTimeline: [],
    events: payload.events ?? [],
    // LLaMA pass-1 stage analysis (issue #306). Forwarded as-is so the
    // {@link StageAiSummary} can render it atomically with the rest of the
    // stage data. Null/undefined when the AI pipeline is off or pending.
    aiAnalysis: payload.aiAnalysis ?? null,
  };
}

export async function resolveStageLabels(
  stages: {
    startPoint: { lat: number; lon: number };
    endPoint: { lat: number; lon: number };
  }[],
  indices?: number[],
  signal?: AbortSignal,
): Promise<void> {
  const store = useTripStore.getState();
  const promises = stages.flatMap((stage, i) => {
    const storeIndex = indices ? (indices[i] ?? i) : i;
    return [
      reverseGeocode(stage.startPoint.lat, stage.startPoint.lon, signal).then(
        (result) => {
          // Drop late responses after the caller aborted (e.g. unmount /
          // trip-switch) so a stale Nominatim reply cannot overwrite the labels
          // of a different trip (#787).
          if (result && !signal?.aborted)
            store.updateStageLabel(storeIndex, "startLabel", result.name);
        },
      ),
      reverseGeocode(stage.endPoint.lat, stage.endPoint.lon, signal).then(
        (result) => {
          if (result && !signal?.aborted)
            store.updateStageLabel(storeIndex, "endLabel", result.name);
        },
      ),
    ];
  });

  await Promise.all(promises);
}

/**
 * Subscribes to Mercure SSE events for a given trip.
 *
 * Opens a persistent SSE connection to the Mercure hub on mount, routing all
 * incoming events through {@link dispatchEvent}. The connection is torn down
 * on unmount or when the `tripId` changes. SSE connectivity state is tracked
 * in {@link useUiStore} (`sseConnected`).
 *
 * In E2E tests, the real Mercure connection is aborted via `page.route()` and
 * events are injected through `CustomEvent('__test_mercure_event')` instead.
 *
 * @param tripId - The trip identifier to subscribe to, or `null` to skip subscription
 */
export function useMercure(
  tripId: string | null,
  mercureToken?: string | null,
): void {
  const clientRef = useRef<MercureClient | null>(null);

  useEffect(() => {
    if (!tripId) return;

    // TODO: wire authHeaderFactory when auth store is implemented (#78)
    const client = new MercureClient(
      resolveMercureHubUrl(),
      `/trips/${tripId}`,
    );
    if (mercureToken) {
      client.setMercureToken(mercureToken);
    }
    clientRef.current = client;
    useUiStore.getState().setSseConnected(true);

    client.onEvent((event) => {
      dispatchEvent(event);
    });

    return () => {
      client.close();
      clientRef.current = null;
      useUiStore.getState().setSseConnected(false);
    };
  }, [tripId, mercureToken]);
}
