"use client";

import {
  useEffect,
  useRef,
  useCallback,
  useMemo,
  useState,
  memo,
} from "react";
import maplibregl from "maplibre-gl";
import "maplibre-gl/dist/maplibre-gl.css";
import { useTheme } from "next-themes";
import { useSyncExternalStore } from "react";
import { useTranslations } from "next-intl";
import { useTripStore } from "@/store/trip-store";
import type { StageData } from "@/lib/validation/schemas";

const STAGE_COLORS = [
  "#e63946",
  "#2a9d8f",
  "#e9c46a",
  "#f4a261",
  "#457b9d",
  "#8338ec",
  "#06d6a0",
  "#fb5607",
  "#3a86ff",
  "#ff006e",
];

const LIGHT_TILES =
  "https://basemaps.cartocdn.com/gl/positron-gl-style/style.json";
const DARK_TILES =
  "https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json";

const emptySubscribe = () => () => {};
const getTrue = () => true;
const getFalse = () => false;

function getStageColor(dayNumber: number): string {
  return STAGE_COLORS[(dayNumber - 1) % STAGE_COLORS.length]!;
}

function buildRouteGeoJSON(
  stages: StageData[],
): GeoJSON.FeatureCollection<GeoJSON.LineString> {
  return {
    type: "FeatureCollection",
    features: stages
      .filter((s) => !s.isRestDay && s.geometry.length >= 2)
      .map((stage) => ({
        type: "Feature",
        properties: {
          dayNumber: stage.dayNumber,
          color: getStageColor(stage.dayNumber),
        },
        geometry: {
          type: "LineString",
          coordinates: stage.geometry.map((pt) => [pt.lon, pt.lat, pt.ele]),
        },
      })),
  };
}

function computeBounds(stages: StageData[]): [[number, number], [number, number]] | null {
  let minLng = Infinity,
    maxLng = -Infinity,
    minLat = Infinity,
    maxLat = -Infinity;
  let hasPoints = false;

  for (const stage of stages) {
    for (const pt of stage.geometry) {
      hasPoints = true;
      if (pt.lon < minLng) minLng = pt.lon;
      if (pt.lon > maxLng) maxLng = pt.lon;
      if (pt.lat < minLat) minLat = pt.lat;
      if (pt.lat > maxLat) maxLat = pt.lat;
    }
    if (stage.geometry.length === 0) {
      hasPoints = true;
      if (stage.startPoint.lon < minLng) minLng = stage.startPoint.lon;
      if (stage.startPoint.lon > maxLng) maxLng = stage.startPoint.lon;
      if (stage.startPoint.lat < minLat) minLat = stage.startPoint.lat;
      if (stage.startPoint.lat > maxLat) maxLat = stage.startPoint.lat;
      if (stage.endPoint.lon < minLng) minLng = stage.endPoint.lon;
      if (stage.endPoint.lon > maxLng) maxLng = stage.endPoint.lon;
      if (stage.endPoint.lat < minLat) minLat = stage.endPoint.lat;
      if (stage.endPoint.lat > maxLat) maxLat = stage.endPoint.lat;
    }
  }

  if (!hasPoints) return null;
  return [
    [minLng, minLat],
    [maxLng, maxLat],
  ];
}

/** Creates a styled DOM element for a map marker (no innerHTML). */
function createMarkerElement(className: string, label: string): HTMLElement {
  const el = document.createElement("div");
  el.className = className;
  el.setAttribute("aria-label", label);
  el.setAttribute("role", "img");
  return el;
}

interface MapViewProps {
  focusedStageIndex: number | null;
  onStageClick: (stageIndex: number) => void;
  onResetView: () => void;
  highlightCoordIndex?: number | null;
  highlightStageIndex?: number | null;
}

export const MapView = memo(function MapView({
  focusedStageIndex,
  onStageClick,
  onResetView,
  highlightCoordIndex,
  highlightStageIndex,
}: MapViewProps) {
  const t = useTranslations("map");
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<maplibregl.Map | null>(null);
  const markersRef = useRef<maplibregl.Marker[]>([]);
  const hoverMarkerRef = useRef<maplibregl.Marker | null>(null);
  const [mapReady, setMapReady] = useState(false);

  const stages = useTripStore((s) => s.stages);
  const { resolvedTheme } = useTheme();
  const mounted = useSyncExternalStore(emptySubscribe, getTrue, getFalse);

  const isDark = mounted && resolvedTheme === "dark";
  const tileStyle = isDark ? DARK_TILES : LIGHT_TILES;

  const activeStages = useMemo(
    () => stages.filter((s) => !s.isRestDay),
    [stages],
  );

  const addSourceAndLayers = useCallback(
    (map: maplibregl.Map, data: GeoJSON.FeatureCollection) => {
      if (!map.getSource("route")) {
        map.addSource("route", { type: "geojson", data });
      } else {
        (map.getSource("route") as maplibregl.GeoJSONSource).setData(data);
      }
      if (!map.getLayer("route-line")) {
        map.addLayer({
          id: "route-line",
          type: "line",
          source: "route",
          layout: { "line-join": "round", "line-cap": "round" },
          paint: {
            "line-color": ["get", "color"],
            "line-width": 4,
            "line-opacity": 0.85,
          },
        });
      }
      if (!map.getLayer("route-hover-target")) {
        map.addLayer({
          id: "route-hover-target",
          type: "line",
          source: "route",
          layout: { "line-join": "round", "line-cap": "round" },
          paint: { "line-color": "rgba(0,0,0,0)", "line-width": 16 },
        });
      }
    },
    [],
  );

  // Initialize map
  useEffect(() => {
    if (!mapContainerRef.current || mapRef.current) return;

    const map = new maplibregl.Map({
      container: mapContainerRef.current,
      style: tileStyle,
      center: [2.35, 48.85],
      zoom: 5,
      attributionControl: false,
    });

    map.addControl(
      new maplibregl.AttributionControl({ compact: true }),
      "bottom-right",
    );
    map.addControl(new maplibregl.NavigationControl(), "top-right");

    map.on("load", () => {
      addSourceAndLayers(map, buildRouteGeoJSON(activeStages));

      map.on("click", "route-hover-target", (e) => {
        const features = e.features;
        if (!features?.length) return;
        const dayNumber = features[0]?.properties?.dayNumber as number | undefined;
        if (dayNumber === undefined) return;
        const idx = activeStages.findIndex((s) => s.dayNumber === dayNumber);
        if (idx !== -1) onStageClick(idx);
      });

      map.on("mouseenter", "route-hover-target", () => {
        map.getCanvas().style.cursor = "pointer";
      });
      map.on("mouseleave", "route-hover-target", () => {
        map.getCanvas().style.cursor = "";
      });

      setMapReady(true);
    });

    mapRef.current = map;

    return () => {
      map.remove();
      mapRef.current = null;
      setMapReady(false);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Update tile style on theme change
  useEffect(() => {
    if (!mapRef.current || !mapReady) return;
    mapRef.current.setStyle(tileStyle);
    mapRef.current.once("style.load", () => {
      if (!mapRef.current) return;
      addSourceAndLayers(mapRef.current, buildRouteGeoJSON(activeStages));
    });
  }, [tileStyle, mapReady, activeStages, addSourceAndLayers]);

  // Update route data when stages change
  useEffect(() => {
    if (!mapRef.current || !mapReady) return;
    const source = mapRef.current.getSource("route") as maplibregl.GeoJSONSource | undefined;
    if (source) {
      source.setData(buildRouteGeoJSON(activeStages));
    }
  }, [activeStages, mapReady]);

  // Rebuild markers when stages change
  useEffect(() => {
    if (!mapRef.current || !mapReady) return;
    const map = mapRef.current;

    for (const marker of markersRef.current) marker.remove();
    markersRef.current = [];

    if (activeStages.length === 0) return;

    const firstStage = activeStages[0];
    const lastStage = activeStages[activeStages.length - 1];
    if (!firstStage || !lastStage) return;

    // Start marker
    const startEl = createMarkerElement("map-marker map-marker--start", t("startMarker"));
    markersRef.current.push(
      new maplibregl.Marker({ element: startEl })
        .setLngLat([firstStage.startPoint.lon, firstStage.startPoint.lat])
        .addTo(map),
    );

    // End marker
    const endEl = createMarkerElement("map-marker map-marker--end", t("endMarker"));
    markersRef.current.push(
      new maplibregl.Marker({ element: endEl })
        .setLngLat([lastStage.endPoint.lon, lastStage.endPoint.lat])
        .addTo(map),
    );

    // Accommodation markers
    activeStages.forEach((stage, idx) => {
      if (idx === activeStages.length - 1) return;
      const acc = stage.selectedAccommodation ?? stage.accommodations[0];
      if (!acc) return;

      const accEl = createMarkerElement(
        "map-marker map-marker--accommodation",
        acc.name,
      );
      markersRef.current.push(
        new maplibregl.Marker({ element: accEl })
          .setLngLat([acc.lon, acc.lat])
          .addTo(map),
      );
    });

    // Alert markers (one per stage, with coords)
    activeStages.forEach((stage) => {
      const alert = stage.alerts.find(
        (a) =>
          (a.type === "critical" || a.type === "warning") &&
          a.lat != null &&
          a.lon != null,
      );
      if (!alert || alert.lat == null || alert.lon == null) return;

      const alertEl = createMarkerElement(
        `map-marker map-marker--alert map-marker--alert-${alert.type}`,
        alert.message,
      );
      markersRef.current.push(
        new maplibregl.Marker({ element: alertEl })
          .setLngLat([alert.lon, alert.lat])
          .addTo(map),
      );
    });
  }, [activeStages, mapReady, t]);

  // Zoom to focused stage or global view
  useEffect(() => {
    if (!mapRef.current || !mapReady || activeStages.length === 0) return;
    const map = mapRef.current;

    if (focusedStageIndex !== null && activeStages[focusedStageIndex]) {
      const stage = activeStages[focusedStageIndex]!;
      const bounds = computeBounds([stage]);
      if (bounds) {
        map.fitBounds(bounds, { padding: 60, maxZoom: 14, duration: 600 });
      }
    } else {
      const bounds = computeBounds(activeStages);
      if (bounds) {
        map.fitBounds(bounds, { padding: 40, maxZoom: 12, duration: 600 });
      }
    }
  }, [focusedStageIndex, activeStages, mapReady]);

  // Hover cursor from elevation profile
  useEffect(() => {
    if (!mapRef.current || !mapReady) return;

    if (hoverMarkerRef.current) {
      hoverMarkerRef.current.remove();
      hoverMarkerRef.current = null;
    }

    if (
      highlightCoordIndex == null ||
      highlightStageIndex == null
    ) {
      return;
    }

    const stage = activeStages[highlightStageIndex];
    if (!stage) return;
    const coord = stage.geometry[highlightCoordIndex];
    if (!coord) return;

    const el = createMarkerElement("map-marker map-marker--hover-cursor", "");
    const marker = new maplibregl.Marker({ element: el, anchor: "center" })
      .setLngLat([coord.lon, coord.lat])
      .addTo(mapRef.current);
    hoverMarkerRef.current = marker;
  }, [highlightCoordIndex, highlightStageIndex, activeStages, mapReady]);

  if (!mounted) {
    return (
      <div
        className="w-full h-full bg-muted rounded-xl animate-pulse"
        aria-label={t("loading")}
      />
    );
  }

  return (
    <div className="relative w-full h-full" data-testid="map-view">
      <div
        ref={mapContainerRef}
        className="w-full h-full rounded-xl overflow-hidden"
        aria-label={t("ariaLabel")}
      />

      {focusedStageIndex !== null && (
        <button
          type="button"
          onClick={onResetView}
          className="absolute top-3 left-3 z-10 bg-background/90 backdrop-blur-sm border border-border text-foreground text-xs font-medium px-3 py-1.5 rounded-lg shadow-sm hover:bg-accent transition-colors cursor-pointer"
          aria-label={t("resetView")}
          data-testid="map-reset-view"
        >
          {t("resetView")}
        </button>
      )}
    </div>
  );
});
