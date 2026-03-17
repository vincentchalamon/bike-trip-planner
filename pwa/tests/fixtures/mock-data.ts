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
          },
        ],
        "1": [
          {
            type: "nudge",
            message: "Passage en altitude (820m)",
            lat: 44.4,
            lon: 4.2,
          },
        ],
      },
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
        osm_scan: "done",
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
            { name: "Cimetière de Vals", lat: 44.62, lon: 4.51, distanceFromStart: 15.0 },
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
            { name: "Boulangerie du Village", category: "bakery", lat: 44.64, lon: 4.48, distanceFromStart: 42.3 },
            { name: "Épicerie Centrale", category: "convenience", lat: 44.641, lon: 4.481, distanceFromStart: 42.5 },
          ],
        },
        {
          type: "both",
          distanceFromStart: 58.7,
          lat: 44.55,
          lon: 4.42,
          water: [
            { name: "Cimetière de Ruoms", lat: 44.55, lon: 4.42, distanceFromStart: 58.7 },
          ],
          food: [
            { name: "Restaurant Les Gorges", category: "restaurant", lat: 44.551, lon: 4.421, distanceFromStart: 58.8 },
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

export function computationErrorEvent(retryable = false): MercureEvent {
  return {
    type: "computation_error",
    data: {
      computation: "weather",
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
