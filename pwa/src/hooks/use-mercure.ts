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
      const stages = event.data.stages.map((s) => ({
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
      }));
      store.setStages(stages);
      resolveStageLabels(stages);
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
        if (!isNaN(idx) && alerts.length > 0) {
          store.updateStageAlerts(idx, alerts);
        }
      }
      break;

    case "calendar_alerts":
      for (const nudge of event.data.nudges) {
        store.updateStageAlerts(nudge.stageIndex, [
          { type: "nudge", message: nudge.message, lat: null, lon: null },
        ]);
      }
      break;

    case "wind_alerts":
      if (event.data.alerts.length > 0) {
        store.updateStageAlerts(0, event.data.alerts);
      }
      break;

    case "resupply_nudges":
      for (const nudge of event.data.nudges) {
        store.updateStageAlerts(nudge.stageIndex, [
          { type: "nudge", message: nudge.message, lat: null, lon: null },
        ]);
      }
      break;

    case "bike_shop_alerts":
      for (const alert of event.data.alerts) {
        store.updateStageAlerts(alert.stageIndex, [
          {
            type: alert.type as "nudge",
            message: alert.message,
            lat: null,
            lon: null,
          },
        ]);
      }
      break;

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
): Promise<void> {
  const store = useTripStore.getState();
  const promises = stages.flatMap((stage, index) => [
    reverseGeocode(stage.startPoint.lat, stage.startPoint.lon).then(
      (result) => {
        if (result) store.updateStageLabel(index, "startLabel", result.name);
      },
    ),
    reverseGeocode(stage.endPoint.lat, stage.endPoint.lon).then((result) => {
      if (result) store.updateStageLabel(index, "endLabel", result.name);
    }),
  ]);

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
