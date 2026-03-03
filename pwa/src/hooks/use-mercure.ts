"use client";

import { useEffect, useRef } from "react";
import { MercureClient } from "@/lib/mercure/client";
import type { MercureEvent } from "@/lib/mercure/types";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { reverseGeocode } from "@/lib/geocode/client";
import { toast } from "sonner";

const MERCURE_URL =
  process.env.NEXT_PUBLIC_MERCURE_URL ??
  "https://localhost/.well-known/mercure";

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
          // Reset derived data for affected or new stages
          return {
            ...s,
            elevationLoss: s.elevationLoss ?? 0,
            geometry: s.geometry ?? [],
            label: s.label ?? null,
            startLabel: null,
            endLabel: null,
            weather: null,
            alerts: [],
            pois: [],
            accommodations: [],
            gpxContent: null,
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
            startLabel: startMatch ? prev.startLabel : null,
            endLabel: endMatch ? prev.endLabel : null,
            weather: null,
            alerts: [],
            pois: [],
            accommodations: endMatch ? prev.accommodations : [],
            gpxContent: null,
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

    case "accommodations_found":
      store.updateStageAccommodations(
        event.data.stageIndex,
        event.data.accommodations,
      );
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

    case "stage_gpx_ready":
      store.updateStageGpx(event.data.stageIndex, event.data.gpxContent);
      break;

    case "trip_complete":
      store.setComputationStatus(event.data.computationStatus);
      useUiStore.getState().setProcessing(false);
      break;

    case "validation_error":
      toast.error(event.data.message);
      useUiStore.getState().setProcessing(false);
      break;

    case "computation_error":
      toast.error(`Computation failed: ${event.data.message}`);
      if (!event.data.retryable) {
        useUiStore.getState().setProcessing(false);
      }
      break;
  }
}

async function resolveStageLabels(
  stages: {
    startPoint: { lat: number; lon: number };
    endPoint: { lat: number; lon: number };
  }[],
  indices?: number[],
): Promise<void> {
  const store = useTripStore.getState();
  const promises = stages.flatMap((stage, i) => {
    const storeIndex = indices ? (indices[i] ?? i) : i;
    return [
      reverseGeocode(stage.startPoint.lat, stage.startPoint.lon).then(
        (result) => {
          if (result)
            store.updateStageLabel(storeIndex, "startLabel", result.name);
        },
      ),
      reverseGeocode(stage.endPoint.lat, stage.endPoint.lon).then((result) => {
        if (result) store.updateStageLabel(storeIndex, "endLabel", result.name);
      }),
    ];
  });

  await Promise.all(promises);
}

export function useMercure(tripId: string | null): void {
  const clientRef = useRef<MercureClient | null>(null);

  useEffect(() => {
    if (!tripId) return;

    const client = new MercureClient(MERCURE_URL, `/trips/${tripId}`);
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
  }, [tripId]);
}
