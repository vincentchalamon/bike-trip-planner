import type { MercureEvent } from "../../src/lib/mercure/types";

export function routeParsedEvent(): MercureEvent {
  return {
    type: "route_parsed",
    data: {
      totalDistance: 187.3,
      totalElevation: 2850,
      totalElevationLoss: 2720,
      sourceType: "komoot_tour",
      title: "Tour de l'Ardeche",
    },
  };
}

export function stagesComputedEvent(): MercureEvent {
  return {
    type: "stages_computed",
    data: {
      stages: [
        {
          dayNumber: 1,
          distance: 72.5,
          elevation: 1180,
          elevationLoss: 920,
          startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
          endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
          geometry: [],
          label: null,
        },
        {
          dayNumber: 2,
          distance: 63.2,
          elevation: 870,
          elevationLoss: 1050,
          startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
          endPoint: { lat: 44.295, lon: 4.087, ele: 360 },
          geometry: [],
          label: null,
        },
        {
          dayNumber: 3,
          distance: 51.6,
          elevation: 800,
          elevationLoss: 750,
          startPoint: { lat: 44.295, lon: 4.087, ele: 360 },
          endPoint: { lat: 44.112, lon: 3.876, ele: 410 },
          geometry: [],
          label: null,
        },
      ],
    },
  };
}

/**
 * Variant of {@link stagesComputedEvent} whose stages carry real geometry, so
 * the elevation profile renders (`ElevationProfile` returns null without it).
 * The default `stagesComputedEvent` intentionally ships empty geometry — some
 * map tests assert the profile is *absent* — so geometry-dependent scenarios
 * (golden-path B/C "carte & profil") inject this variant explicitly instead.
 */
export function stagesComputedEventWithGeometry(): MercureEvent {
  const geometryFor = (
    a: { lat: number; lon: number; ele: number },
    mid: { lat: number; lon: number; ele: number },
    b: { lat: number; lon: number; ele: number },
  ) => [a, mid, b];
  return {
    type: "stages_computed",
    data: {
      stages: [
        {
          dayNumber: 1,
          distance: 72.5,
          elevation: 1180,
          elevationLoss: 920,
          startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
          endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
          geometry: geometryFor(
            { lat: 44.735, lon: 4.598, ele: 280 },
            { lat: 44.62, lon: 4.46, ele: 650 },
            { lat: 44.532, lon: 4.392, ele: 540 },
          ),
          label: null,
        },
        {
          dayNumber: 2,
          distance: 63.2,
          elevation: 870,
          elevationLoss: 1050,
          startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
          endPoint: { lat: 44.295, lon: 4.087, ele: 360 },
          geometry: geometryFor(
            { lat: 44.532, lon: 4.392, ele: 540 },
            { lat: 44.38, lon: 4.2, ele: 480 },
            { lat: 44.295, lon: 4.087, ele: 360 },
          ),
          label: null,
        },
        {
          dayNumber: 3,
          distance: 51.6,
          elevation: 800,
          elevationLoss: 750,
          startPoint: { lat: 44.295, lon: 4.087, ele: 360 },
          endPoint: { lat: 44.112, lon: 3.876, ele: 410 },
          geometry: geometryFor(
            { lat: 44.295, lon: 4.087, ele: 360 },
            { lat: 44.2, lon: 3.98, ele: 520 },
            { lat: 44.112, lon: 3.876, ele: 410 },
          ),
          label: null,
        },
      ],
    },
  };
}

export function weatherFetchedEvent(): MercureEvent {
  return {
    type: "weather_fetched",
    data: {
      stages: [
        {
          dayNumber: 1,
          weather: {
            icon: "02d",
            description: "Partly cloudy",
            tempMin: 14,
            tempMax: 26,
            windSpeed: 12,
            windDirection: "NO",
            precipitationProbability: 10,
            humidity: 65,
            comfortIndex: 78,
            relativeWindDirection: "crosswind",
          },
        },
        {
          dayNumber: 2,
          weather: {
            icon: "01d",
            description: "Clear sky",
            tempMin: 16,
            tempMax: 28,
            windSpeed: 8,
            windDirection: "SO",
            precipitationProbability: 5,
            humidity: 55,
            comfortIndex: 85,
            relativeWindDirection: "tailwind",
          },
        },
        {
          dayNumber: 3,
          weather: {
            icon: "03d",
            description: "Overcast",
            tempMin: 12,
            tempMax: 22,
            windSpeed: 15,
            windDirection: "N",
            precipitationProbability: 30,
            humidity: 75,
            comfortIndex: 60,
            relativeWindDirection: "headwind",
          },
        },
      ],
    },
  };
}

export function accommodationsFoundEvent(
  stageIndex: number,
  searchRadiusKm = 5,
): MercureEvent {
  return {
    type: "accommodations_found",
    data: {
      stageIndex,
      searchRadiusKm,
      accommodations: [
        {
          name: "Camping Les Oliviers",
          type: "camp_site",
          lat: 44.5,
          lon: 4.38,
          estimatedPriceMin: 12,
          estimatedPriceMax: 18,
          isExactPrice: false,
          possibleClosed: false,
          distanceToEndPoint: 1.2,
          // Provisioning-time enrichment (issue #870): a DataTourisme entry with
          // its Wikidata payload, so specs can assert the source badge, the
          // thumbnail and the Wikipedia link survive a reload.
          source: "datatourisme",
          description: "Camping ombragé au bord de l'Ardèche.",
          imageUrl: "https://example.com/oliviers.jpg",
          wikipediaUrl: "https://fr.wikipedia.org/wiki/Camping",
          openingHours: "Apr-Oct 08:00-20:00",
          // Schemeless `website` tag (issue #867): must render as an absolute
          // link instead of throwing during render.
          url: "www.camping-les-oliviers.fr",
        },
        {
          name: "Hotel du Pont",
          type: "hotel",
          lat: 44.51,
          lon: 4.39,
          estimatedPriceMin: 65,
          estimatedPriceMax: 85,
          isExactPrice: false,
          possibleClosed: false,
          distanceToEndPoint: 0.5,
          source: "osm",
          description: "Hôtel familial à deux pas du pont.",
          // Unusable OSM `website` tag: renders no link at all, no error.
          url: "appeler le 06 12 34 56 78",
          // Contact block and OSM identity (issue #873): the phone renders as a
          // `tel:` link, and the (way, 42) pair as a link to that exact object —
          // a hardcoded `node` would point at a different feature entirely.
          phone: "+33 4 75 00 00 00",
          osmType: "way",
          osmId: 42,
        },
      ],
    },
  };
}

export function emptyAccommodationsFoundEvent(
  stageIndex: number,
  searchRadiusKm = 5,
): MercureEvent {
  return {
    type: "accommodations_found",
    data: {
      stageIndex,
      searchRadiusKm,
      accommodations: [],
    },
  };
}

export function terrainAlertsEvent(): MercureEvent {
  return {
    type: "terrain_alerts",
    data: {
      alertsByStage: {
        "0": [
          {
            type: "warning",
            message: "Route non goudronnee sur 3km",
            lat: 44.6,
            lon: 4.5,
            action: {
              kind: "detour",
              label: "Show alternative",
              payload: { alternativeId: "alt-1" },
            },
          },
        ],
        "1": [
          {
            type: "nudge",
            message: "Passage en altitude (820m)",
            lat: 44.4,
            lon: 4.2,
            action: {
              kind: "dismiss",
              label: "Dismiss",
              payload: {},
            },
          },
        ],
      },
    },
  };
}

/**
 * Terrain alerts in the wire format the backend emits since issue #863:
 * coordinates AND the contextual action travel with the live event, and only the
 * kinds the frontend wires (`navigate`, `dismiss`) are transmitted — the
 * `auto_fix` action of the elevation rule is dropped server-side, so its alert
 * arrives without any action at all.
 */
export function terrainAlertsWithServerFilteredActionsEvent(): MercureEvent {
  return {
    type: "terrain_alerts",
    data: {
      alertsByStage: {
        "0": [
          {
            type: "critical",
            message: "Discontinuity between stage 1 and 2",
            lat: 44.61,
            lon: 4.51,
            action: {
              kind: "navigate",
              label: "Show the discontinuity on the map",
              payload: { lat: 44.61, lon: 4.51 },
            },
          },
        ],
        "1": [
          {
            type: "warning",
            message: "Significant elevation gain (1200m)",
            lat: 44.4,
            lon: 4.2,
          },
        ],
      },
    },
  };
}

/**
 * Calendar alerts on the post-#864 contract: the `alerts` key (was `nudges`),
 * a `dayNumber`, and an `AlertType` value carried in `type` rather than the
 * literal `"nudge"` the frontend used to hardcode.
 */
export function calendarAlertsEvent(): MercureEvent {
  return {
    type: "calendar_alerts",
    data: {
      alerts: [
        {
          stageIndex: 0,
          dayNumber: 1,
          code: "calendar_public_holiday",
          type: "nudge",
          message: "L'etape 1 coincide avec un jour ferie (La Fete nationale)",
          date: "2026-07-14",
        },
        {
          stageIndex: 1,
          dayNumber: 2,
          code: "calendar_sunday",
          type: "warning",
          message: "L'etape 2 tombe un dimanche",
          date: "2026-07-19",
        },
      ],
    },
  };
}

/**
 * A critical terrain alert whose `navigate` action carries the geometry of the
 * concerned road stretch (`segments`), highlighted on the internal map — the
 * post-#982 contract that replaces the old OSM external link.
 */
export function terrainAlertWithSegmentsEvent(): MercureEvent {
  return {
    type: "terrain_alerts",
    data: {
      alertsByStage: {
        "0": [
          {
            type: "critical",
            code: "traffic_main_road",
            message: "1 segment on main road without bike lane (1.2 km)",
            lat: 44.6,
            lon: 4.5,
            action: {
              kind: "navigate",
              label: "See the segment on the map",
              payload: {
                lat: 44.6,
                lon: 4.5,
                segments: [
                  [
                    [44.6, 4.5],
                    [44.58, 4.48],
                    [44.55, 4.45],
                  ],
                ],
              },
            },
          },
        ],
      },
    },
  };
}

export function alertsWithActionsEvent(): MercureEvent {
  return {
    type: "terrain_alerts",
    data: {
      alertsByStage: {
        "0": [
          {
            type: "warning",
            message: "Steep gradient detected (12%)",
            lat: 44.6,
            lon: 4.5,
            action: {
              kind: "navigate",
              label: "Zoom to location",
              payload: { lat: 44.6, lon: 4.5 },
            },
          },
          {
            type: "nudge",
            message: "Minor road surface issue",
            lat: 44.55,
            lon: 4.45,
            action: {
              kind: "dismiss",
              label: "Got it",
              payload: {},
            },
          },
        ],
        "1": [
          {
            type: "critical",
            message: "E-bike range exceeded",
            lat: 44.4,
            lon: 4.2,
            action: {
              kind: "auto_fix",
              label: "Split stage",
              payload: { splitAt: 45.0 },
            },
          },
        ],
        "2": [
          {
            type: "warning",
            message: "Difficult terrain ahead",
            lat: 44.295,
            lon: 4.087,
            action: {
              kind: "detour",
              label: "Take detour",
              payload: {},
            },
          },
        ],
      },
    },
  };
}

export function culturalPoiAlertsEvent(): MercureEvent {
  return {
    type: "cultural_poi_alerts",
    data: {
      alerts: [
        {
          stageIndex: 0,
          dayNumber: 1,
          code: "cultural_poi_suggestion",
          type: "nudge",
          message:
            "Point d'intérêt culturel à proximité de l'étape 1 : Château de Ventadour (castle, 320m du tracé). L'ajouter à votre itinéraire ?",
          lat: 44.71,
          lon: 4.57,
          poiName: "Château de Ventadour",
          poiType: "castle",
          poiLat: 44.71,
          poiLon: 4.57,
          distanceFromRoute: 320,
        },
      ],
    },
  };
}

export function routeSegmentRecalculatedEvent(stageIndex = 0): MercureEvent {
  return {
    type: "route_segment_recalculated",
    data: {
      stageIndex,
      reason: "poi_detour",
      distance: 75200,
      elevationGain: 1240,
      duration: 18000,
      coordinates: [
        { lat: 44.735, lon: 4.598, ele: 280 },
        { lat: 44.71, lon: 4.57, ele: 320 },
        { lat: 44.532, lon: 4.392, ele: 540 },
      ],
    },
  };
}

export function tripCompleteEvent(): MercureEvent {
  return {
    type: "trip_complete",
    data: {
      computationStatus: {
        route: "done",
        stages: "done",
        weather: "done",
        terrain: "done",
        accommodations: "done",
      },
    },
  };
}

export function supplyTimelineEvent(stageIndex: number): MercureEvent {
  return {
    type: "supply_timeline",
    data: {
      stageIndex,
      markers: [
        {
          type: "water",
          distanceFromStart: 15.0,
          lat: 44.62,
          lon: 4.51,
          water: [
            {
              name: "Cimetière de Vals",
              lat: 44.62,
              lon: 4.51,
              distanceFromStart: 15.0,
            },
          ],
          food: [],
        },
        {
          type: "food",
          distanceFromStart: 42.3,
          lat: 44.64,
          lon: 4.48,
          water: [],
          food: [
            {
              name: "Boulangerie du Village",
              category: "bakery",
              lat: 44.64,
              lon: 4.48,
              distanceFromStart: 42.3,
            },
            {
              name: "Épicerie Centrale",
              category: "convenience",
              lat: 44.641,
              lon: 4.481,
              distanceFromStart: 42.5,
            },
          ],
        },
        {
          type: "both",
          distanceFromStart: 58.7,
          lat: 44.55,
          lon: 4.42,
          water: [
            {
              name: "Cimetière de Ruoms",
              lat: 44.55,
              lon: 4.42,
              distanceFromStart: 58.7,
            },
          ],
          food: [
            {
              name: "Restaurant Les Gorges",
              category: "restaurant",
              lat: 44.551,
              lon: 4.421,
              distanceFromStart: 58.8,
            },
          ],
        },
      ],
    },
  };
}

export function supplyTimelineClusterEvent(stageIndex = 0): MercureEvent {
  return {
    type: "supply_timeline",
    data: {
      stageIndex,
      markers: [
        {
          type: "water",
          distanceFromStart: 20,
          lat: 48.1,
          lon: 2.1,
          water: [
            {
              name: "Fontaine A",
              lat: 48.1,
              lon: 2.1,
              distanceFromStart: 20,
            },
          ],
          food: [],
        },
        {
          type: "food",
          distanceFromStart: 22,
          lat: 48.2,
          lon: 2.2,
          water: [],
          food: [
            {
              name: "Boulangerie B",
              category: "bakery",
              lat: 48.2,
              lon: 2.2,
              distanceFromStart: 22,
            },
          ],
        },
      ],
    },
  };
}

export function validationErrorEvent(): MercureEvent {
  return {
    type: "validation_error",
    data: {
      code: "INVALID_SOURCE",
      message: "URL source invalide ou inaccessible.",
    },
  };
}

export function computationErrorEvent(
  retryable = false,
  computation = "weather",
): MercureEvent {
  return {
    type: "computation_error",
    data: {
      computation,
      message: "Service meteo temporairement indisponible.",
      retryable,
    },
  };
}

export function fullTripEventSequence(): MercureEvent[] {
  return [
    routeParsedEvent(),
    stagesComputedEvent(),
    weatherFetchedEvent(),
    accommodationsFoundEvent(0),
    accommodationsFoundEvent(1),
    terrainAlertsEvent(),
    tripCompleteEvent(),
  ];
}

export function computationStepCompletedEvent(
  step: string,
  category:
    | "route"
    | "points_of_interest"
    | "accommodations"
    | "terrain_security"
    | "weather"
    | "context",
  completed: number,
  total: number,
): MercureEvent {
  return {
    type: "computation_step_completed",
    data: { step, category, completed, total },
  };
}

export function tripReadyEvent(): MercureEvent {
  return {
    type: "trip_ready",
    data: {
      stages: [
        {
          dayNumber: 1,
          distance: 72.5,
          elevation: 1180,
          elevationLoss: 920,
          startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
          endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
          geometry: [
            { lat: 44.735, lon: 4.598, ele: 280 },
            { lat: 44.532, lon: 4.392, ele: 540 },
          ],
          label: null,
          isRestDay: false,
          weather: {
            icon: "02d",
            description: "Partly cloudy",
            tempMin: 14,
            tempMax: 26,
            windSpeed: 12,
            windDirection: "NO",
            precipitationProbability: 10,
            humidity: 65,
            comfortIndex: 78,
            relativeWindDirection: "crosswind",
          },
          alerts: [],
          pois: [],
          accommodations: [],
          selectedAccommodation: null,
          events: [],
        },
        {
          dayNumber: 2,
          distance: 63.2,
          elevation: 870,
          elevationLoss: 1050,
          startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
          endPoint: { lat: 44.295, lon: 4.087, ele: 360 },
          geometry: [
            { lat: 44.532, lon: 4.392, ele: 540 },
            { lat: 44.295, lon: 4.087, ele: 360 },
          ],
          label: null,
          isRestDay: false,
          weather: null,
          alerts: [],
          pois: [],
          accommodations: [],
          selectedAccommodation: null,
          events: [],
        },
      ],
      computationStatus: {
        route: "done",
        stages: "done",
        weather: "done",
        terrain: "done",
        accommodations: "done",
      },
      aiOverview: null,
    },
  };
}

/**
 * Variant of {@link tripReadyEvent} that carries per-stage `aiAnalysis`
 * payloads, used by issue #306 tests to assert the {@link StageAiSummary}
 * component renders the narrative, insights, suggestions, and the collapsible
 * top-3 alerts preview produced by the LLaMA pass 1.
 *
 * Stage 1 ships with 7 alerts (a mix of severities) so the preview / "show
 * more" toggle can be exercised. Stage 2 ships with no analysis to verify
 * the silent fallback to the legacy fully-expanded alert list.
 */
export function tripReadyEventWithStageAiAnalysis(): MercureEvent {
  const base = tripReadyEvent();
  if (base.type !== "trip_ready") {
    throw new Error("tripReadyEvent() must return a trip_ready event");
  }
  return {
    type: "trip_ready",
    data: {
      ...base.data,
      stages: [
        {
          ...base.data.stages[0]!,
          alerts: [
            {
              type: "warning",
              message: "Pente raide km 55-58 (10%)",
              lat: null,
              lon: null,
            },
            {
              type: "warning",
              message: "Route sans piste cyclable km 60-65",
              lat: null,
              lon: null,
            },
            {
              type: "nudge",
              message: "Aucun point d'eau entre km 45 et km 72",
              lat: null,
              lon: null,
            },
            {
              type: "critical",
              message: "Tronçon non praticable km 20",
              lat: null,
              lon: null,
            },
            {
              type: "warning",
              message: "Trafic dense en sortie de Cassel",
              lat: null,
              lon: null,
            },
            {
              type: "nudge",
              message: "Pause ombragée recommandée vers km 30",
              lat: null,
              lon: null,
            },
            {
              type: "nudge",
              message: "Boulangerie ouverte tôt à Cassel",
              lat: null,
              lon: null,
            },
          ],
          aiAnalysis: {
            narrative:
              "Journée exigeante : le D+ est concentré sur la première moitié,\n" +
              "puis la route s'aplatit le long de la côte vers Boulogne.",
            insights: [
              "Pente moyenne de 6% entre les km 55 et 58.",
              "Vent dominant de nord-ouest sur la deuxième moitié.",
            ],
            suggestions: [
              "Démarrer tôt pour éviter la chaleur de l'après-midi.",
              "Prévoir une recharge d'eau avant le km 45.",
              "Réduire l'allure dans la portion vallonée.",
            ],
            model: "llama3.1:8b",
            promptVersion: 1,
            generatedAt: "2026-05-11T08:30:00Z",
          },
        },
        {
          ...base.data.stages[1]!,
          alerts: [
            {
              type: "nudge",
              message: "Pas d'alerte critique sur le jour 2",
              lat: null,
              lon: null,
            },
          ],
          aiAnalysis: null,
        },
      ],
    },
  };
}

/**
 * Variant of {@link tripReadyEvent} that carries a populated `aiOverview`
 * payload, used by issue #305 tests to assert the {@link TripAiOverview}
 * component renders the narrative, patterns, recommendations, and cross-stage
 * alerts produced by the LLaMA pass 2.
 */
export function tripReadyEventWithAiOverview(): MercureEvent {
  const base = tripReadyEvent();
  if (base.type !== "trip_ready") {
    throw new Error("tripReadyEvent() must return a trip_ready event");
  }
  return {
    type: "trip_ready",
    data: {
      ...base.data,
      aiOverview: {
        narrative:
          "Votre traversée de l'Ardèche s'étire sur deux jours bien rythmés.\n" +
          "Le premier jour concentre le dénivelé positif, le second est plus roulant.",
        patterns: [
          "Dénivelé positif majoritairement sur le jour 1 (1180 m).",
          "Vent dominant de secteur ouest sur les deux étapes.",
        ],
        recommendations: [
          "Démarrer tôt le jour 1 pour profiter de la fraîcheur matinale.",
          "Prévoir un ravitaillement complet avant Les Vans.",
        ],
        crossStageAlerts: [
          "Charge cumulative supérieure à la moyenne — surveiller la fatigue J2.",
        ],
        model: "llama3.1:8b",
        promptVersion: 1,
        generatedAt: "2026-05-11T08:30:00Z",
      },
    },
  };
}

export function stageUpdatedEvent(stageIndex: number): MercureEvent {
  return {
    type: "stage_updated",
    data: {
      stageIndex,
      stage: {
        dayNumber: stageIndex + 1,
        distance: 55.0,
        elevation: 720,
        elevationLoss: 640,
        startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
        endPoint: { lat: 44.5, lon: 4.4, ele: 500 },
        geometry: [
          { lat: 44.735, lon: 4.598, ele: 280 },
          { lat: 44.5, lon: 4.4, ele: 500 },
        ],
        label: null,
        isRestDay: false,
        weather: null,
        alerts: [],
        pois: [],
        accommodations: [],
        selectedAccommodation: null,
        events: [],
      },
    },
  };
}

export function stageUpdatedEventWithSelectedAccommodation(
  stageIndex: number,
): MercureEvent {
  const hotelDuPont = {
    name: "Hotel du Pont",
    type: "hotel",
    lat: 44.51,
    lon: 4.39,
    estimatedPriceMin: 65,
    estimatedPriceMax: 85,
    isExactPrice: false,
    possibleClosed: false,
    distanceToEndPoint: 0.5,
    source: "osm" as const,
  };
  return {
    type: "stage_updated",
    data: {
      stageIndex,
      stage: {
        dayNumber: stageIndex + 1,
        distance: 55.0,
        elevation: 720,
        elevationLoss: 640,
        startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
        endPoint: { lat: hotelDuPont.lat, lon: hotelDuPont.lon, ele: 0 },
        geometry: [
          { lat: 44.735, lon: 4.598, ele: 280 },
          { lat: hotelDuPont.lat, lon: hotelDuPont.lon, ele: 0 },
        ],
        label: null,
        isRestDay: false,
        weather: null,
        alerts: [],
        pois: [],
        accommodations: [hotelDuPont],
        selectedAccommodation: hotelDuPont,
        events: [],
      },
    },
  };
}

/**
 * Wire shape of a single POI suggestion, matching `PoiSuggestionDto` (#934).
 */
export interface NearbyPoiWire {
  name: string;
  category: string;
  lat: number;
  lon: number;
  distance_m: number;
  detour_m: number | null;
  opening_hours_today: string | null;
  closes_at: string | null;
  phone: string | null;
  deeplink: string;
  warning: "closes_soon" | "far_from_route" | "hours_unverified" | null;
  warning_minutes: number | null;
}

/** Build a single in-ride POI suggestion; override any field. */
export function nearbyPoiSuggestion(
  overrides: Partial<NearbyPoiWire> = {},
): NearbyPoiWire {
  return {
    name: "Fontaine du Village",
    category: "water",
    lat: 44.7,
    lon: 4.6,
    distance_m: 320,
    detour_m: 150,
    opening_hours_today: null,
    closes_at: null,
    phone: null,
    deeplink: "https://www.google.com/maps/search/?api=1&query=44.7,4.6",
    warning: null,
    warning_minutes: null,
    ...overrides,
  };
}

/** Wire shape of the guided in-ride search response (#934/#935). */
export interface NearbyPoiSearchWire {
  "@id": string;
  "@type": string;
  tripId: string;
  category: string;
  radiusMeters: number;
  totalFound: number;
  capReached: boolean;
  outOfCoverage: boolean;
  pois: NearbyPoiWire[];
}

/**
 * Build a `POST /trips/{id}/nearby-pois` response (#935). Defaults to three
 * water POIs within a 3 km radius; override to exercise the truncated, empty,
 * cap-reached and out-of-coverage recap states.
 */
export function nearbyPoiSearchResponse(
  overrides: Partial<NearbyPoiSearchWire> = {},
): NearbyPoiSearchWire {
  const category = overrides.category ?? "water";
  return {
    "@id": "/trips/test-trip-abc-123/nearby-pois",
    "@type": "Trip.NearbyPoiSearchResponse",
    tripId: "test-trip-abc-123",
    category,
    radiusMeters: 3000,
    totalFound: 3,
    capReached: false,
    outOfCoverage: false,
    pois: [
      nearbyPoiSuggestion({
        category,
        name: "Fontaine du Village",
        distance_m: 320,
      }),
      nearbyPoiSuggestion({
        category,
        name: "Fontaine de la Place",
        distance_m: 540,
      }),
      nearbyPoiSuggestion({
        category,
        name: "Source du Pont",
        distance_m: 780,
      }),
    ],
    ...overrides,
  };
}
