"use client";

import { useEffect, useRef, useCallback, useMemo, useState, memo } from "react";
import maplibregl from "maplibre-gl";
import "maplibre-gl/dist/maplibre-gl.css";
import { useTheme } from "next-themes";
import { useSyncExternalStore } from "react";
import { useTranslations } from "next-intl";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import type { StageData } from "@/lib/validation/schemas";
import { getStageColor } from "./stage-colors";
import { createCategoryMarkerElement } from "./icons/markerDom";
import { resolveCategory, type MarkerCategory } from "./icons";
import { MapLegend } from "@/components/map-legend";

const LIGHT_TILES =
  "https://basemaps.cartocdn.com/gl/positron-gl-style/style.json";
const DARK_TILES =
  "https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json";

const emptySubscribe = () => () => {};
const getTrue = () => true;
const getFalse = () => false;

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

function computeBounds(
  stages: StageData[],
): [[number, number], [number, number]] | null {
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

/**
 * Background colour applied behind the unified accommodation icon — kept
 * granular per sub-type so users still get a quick visual cue at a glance.
 */
function getAccommodationBackground(type: string): string {
  switch (type) {
    case "hotel":
    case "hostel":
    case "guest_house":
    case "motel":
    case "chalet":
      return "#7c3aed"; // violet — buildings
    case "camp_site":
      return "#059669"; // emerald — camping
    case "alpine_hut":
    case "wilderness_hut":
    case "shelter":
      return "#b45309"; // amber-700 — huts
    default:
      return "#6b7280"; // slate — fallback
  }
}

/**
 * Marker category mapped to the alert source for {@link MarkerIcon} lookup.
 * Falls back to user-waypoint when nothing matches.
 */
function resolveAlertCategory(source: string | undefined): MarkerCategory {
  return resolveCategory(source ?? "") ?? "user-waypoint";
}

function addAccommodationLinkLayer(map: maplibregl.Map): void {
  map.addSource("accommodation-link", {
    type: "geojson",
    data: { type: "FeatureCollection", features: [] },
  });
  map.addLayer({
    id: "accommodation-dashed-line",
    type: "line",
    source: "accommodation-link",
    paint: {
      "line-color": "#7c3aed",
      "line-width": 1.5,
      "line-dasharray": [4, 4],
      "line-opacity": 0.6,
    },
  });
}

interface MapViewProps {
  focusedStageIndex: number | null;
  onStageClick: (stageIndex: number) => void;
  onResetView: () => void;
  highlightCoordIndex?: number | null;
  highlightStageIndex?: number | null;
  stages?: StageData[];
}

export const MapView = memo(function MapView({
  focusedStageIndex,
  onStageClick,
  onResetView,
  highlightCoordIndex,
  highlightStageIndex,
  stages: externalStages,
}: MapViewProps) {
  const t = useTranslations("map");
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<maplibregl.Map | null>(null);
  const markersRef = useRef<maplibregl.Marker[]>([]);
  const hoverMarkerRef = useRef<maplibregl.Marker | null>(null);
  const accMarkerElementsRef = useRef<Map<string, HTMLElement>>(new Map());
  const [mapReady, setMapReady] = useState(false);

  const storeStages = useTripStore((s) => s.stages);
  const stages = externalStages ?? storeStages;
  const hoveredAccommodation = useUiStore((s) => s.hoveredAccommodation);
  const setHoveredAccommodation = useUiStore((s) => s.setHoveredAccommodation);
  const { resolvedTheme } = useTheme();
  const mounted = useSyncExternalStore(emptySubscribe, getTrue, getFalse);

  const isDark = mounted && resolvedTheme === "dark";
  const tileStyle = isDark ? DARK_TILES : LIGHT_TILES;
  // Track the last applied tile style to skip redundant setStyle() calls.
  // Without this, the effect also fires when mapReady flips to true, which
  // resets the style and briefly removes route sources/layers.
  const appliedTileStyleRef = useRef(tileStyle);

  const activeStages = useMemo(
    () => stages.filter((s) => !s.isRestDay),
    [stages],
  );

  // Refs to avoid stale closures in map event handlers that are registered once
  const activeStagesRef = useRef(activeStages);
  const onStageClickRef = useRef(onStageClick);
  const setHoveredAccommodationRef = useRef(setHoveredAccommodation);
  useEffect(() => {
    activeStagesRef.current = activeStages;
  });
  useEffect(() => {
    onStageClickRef.current = onStageClick;
  });
  useEffect(() => {
    setHoveredAccommodationRef.current = setHoveredAccommodation;
  });

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

      // Accommodation link dashed line (empty by default, updated on hover)
      addAccommodationLinkLayer(map);

      map.on("click", "route-hover-target", (e) => {
        const features = e.features;
        if (!features?.length) return;
        const dayNumber = features[0]?.properties?.dayNumber as
          | number
          | undefined;
        if (dayNumber === undefined) return;
        const idx = activeStagesRef.current.findIndex(
          (s) => s.dayNumber === dayNumber,
        );
        if (idx !== -1) onStageClickRef.current(idx);
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

  // Update tile style on theme change — but NOT on the initial mapReady flip.
  // The map is already initialised with the correct style; calling setStyle()
  // again at that point wipes sources/layers until "style.load" re-adds them.
  useEffect(() => {
    if (!mapRef.current || !mapReady) return;
    if (tileStyle === appliedTileStyleRef.current) return;
    appliedTileStyleRef.current = tileStyle;
    mapRef.current.setStyle(tileStyle);
    mapRef.current.once("style.load", () => {
      if (!mapRef.current) return;
      addSourceAndLayers(
        mapRef.current,
        buildRouteGeoJSON(activeStagesRef.current),
      );
      // Re-add accommodation link source/layer after style change
      if (!mapRef.current.getSource("accommodation-link")) {
        addAccommodationLinkLayer(mapRef.current);
      }
    });
  }, [tileStyle, mapReady, addSourceAndLayers]);

  // Update route data when stages change
  useEffect(() => {
    if (!mapRef.current || !mapReady) return;
    const source = mapRef.current.getSource("route") as
      | maplibregl.GeoJSONSource
      | undefined;
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
    const startEl = createMarkerElement(
      "map-marker map-marker--start",
      t("startMarker"),
    );
    markersRef.current.push(
      new maplibregl.Marker({ element: startEl })
        .setLngLat([firstStage.startPoint.lon, firstStage.startPoint.lat])
        .addTo(map),
    );

    // End marker
    const endEl = createMarkerElement(
      "map-marker map-marker--end",
      t("endMarker"),
    );
    markersRef.current.push(
      new maplibregl.Marker({ element: endEl })
        .setLngLat([lastStage.endPoint.lon, lastStage.endPoint.lat])
        .addTo(map),
    );

    // Accommodation markers — per-accommodation with category styling
    accMarkerElementsRef.current.clear();
    activeStages.forEach((stage, stageIdx) => {
      if (stageIdx === activeStages.length - 1) return;

      if (stage.selectedAccommodation) {
        // Only show the selected accommodation for this stage
        const el = createCategoryMarkerElement("accommodation", {
          label: stage.selectedAccommodation.name,
          background: getAccommodationBackground(
            stage.selectedAccommodation.type,
          ),
          size: 28,
          extraClass: "map-marker--acc map-marker--acc-selected",
        });
        markersRef.current.push(
          new maplibregl.Marker({ element: el })
            .setLngLat([
              stage.selectedAccommodation.lon,
              stage.selectedAccommodation.lat,
            ])
            .addTo(map),
        );
      } else {
        // Show all accommodations for this stage
        stage.accommodations.forEach((acc, accIdx) => {
          const key = `${stageIdx}-${accIdx}`;
          const el = createCategoryMarkerElement("accommodation", {
            label: acc.name,
            background: getAccommodationBackground(acc.type),
            size: 24,
            extraClass: "map-marker--acc",
          });
          accMarkerElementsRef.current.set(key, el);

          // Bidirectional hover: map marker → store
          el.addEventListener("mouseenter", () => {
            setHoveredAccommodationRef.current({
              stageIndex: stageIdx,
              accIndex: accIdx,
            });
          });
          el.addEventListener("mouseleave", () => {
            setHoveredAccommodationRef.current(null);
          });

          markersRef.current.push(
            new maplibregl.Marker({ element: el })
              .setLngLat([acc.lon, acc.lat])
              .addTo(map),
          );
        });
      }
    });

    // Alert markers (one per stage, with coords). The icon comes from the
    // unified registry, picked from `alert.source` (e.g. "railway_station",
    // "border_crossing", "cultural_poi"…). Falls back to a generic warning
    // disc when no category can be resolved.
    activeStages.forEach((stage) => {
      const alert = stage.alerts.find(
        (a) =>
          (a.type === "critical" || a.type === "warning") &&
          a.lat != null &&
          a.lon != null,
      );
      if (!alert || alert.lat == null || alert.lon == null) return;

      const category = resolveAlertCategory(alert.source);
      const background = alert.type === "critical" ? "#dc2626" : "#d97706";
      const isEnrichedPoi =
        category === "cultural-poi" &&
        Boolean(
          alert.description ?? alert.openingHours ?? alert.estimatedPrice,
        );

      const alertEl = createCategoryMarkerElement(category, {
        label: alert.message,
        background,
        size: 24,
        extraClass: `map-marker--alert map-marker--alert-${alert.type}${
          isEnrichedPoi ? " map-marker--cultural-enriched" : ""
        }`,
      });
      // The disc keeps relative positioning so the optional ::after dot
      // (cultural-poi enrichment indicator) anchors correctly.
      alertEl.style.position = "relative";
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

  // Hover highlight — toggle CSS class on accommodation markers without rebuilding
  useEffect(() => {
    for (const el of accMarkerElementsRef.current.values()) {
      el.classList.remove("map-marker--acc-highlighted");
    }
    if (hoveredAccommodation) {
      const key = `${hoveredAccommodation.stageIndex}-${hoveredAccommodation.accIndex}`;
      const el = accMarkerElementsRef.current.get(key);
      if (el) el.classList.add("map-marker--acc-highlighted");
    }

    // Update accommodation link dashed line
    const source = mapRef.current?.getSource("accommodation-link") as
      | maplibregl.GeoJSONSource
      | undefined;
    if (source) {
      if (hoveredAccommodation) {
        const stage = activeStages[hoveredAccommodation.stageIndex];
        const acc = stage?.accommodations[hoveredAccommodation.accIndex];
        if (
          acc &&
          !stage.selectedAccommodation &&
          (acc.distanceToEndPoint ?? 0) > 0.2
        ) {
          source.setData({
            type: "FeatureCollection",
            features: [
              {
                type: "Feature",
                properties: {},
                geometry: {
                  type: "LineString",
                  coordinates: [
                    [stage.endPoint.lon, stage.endPoint.lat],
                    [acc.lon, acc.lat],
                  ],
                },
              },
            ],
          });
        } else {
          source.setData({ type: "FeatureCollection", features: [] });
        }
      } else {
        source.setData({ type: "FeatureCollection", features: [] });
      }
    }
  }, [hoveredAccommodation, activeStages]);

  // Hover cursor from elevation profile
  useEffect(() => {
    if (!mapRef.current || !mapReady) return;

    if (hoverMarkerRef.current) {
      hoverMarkerRef.current.remove();
      hoverMarkerRef.current = null;
    }

    if (highlightCoordIndex == null || highlightStageIndex == null) {
      return;
    }

    const stage = activeStages[highlightStageIndex];
    if (!stage) return;
    const coord = stage.geometry[highlightCoordIndex];
    if (!coord) return;

    const el = createMarkerElement("map-marker map-marker--hover-cursor", "");
    const dot = document.createElement("div");
    dot.className = "map-hover-dot";
    el.appendChild(dot);
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

      <MapLegend />
    </div>
  );
});
