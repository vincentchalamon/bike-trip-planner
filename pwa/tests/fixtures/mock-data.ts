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
            windDirection: "NW",
            precipitationProbability: 10,
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
            windDirection: "SW",
            precipitationProbability: 5,
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
          },
        },
      ],
    },
  };
}

export function accommodationsFoundEvent(stageIndex: number): MercureEvent {
  return {
    type: "accommodations_found",
    data: {
      stageIndex,
      accommodations: [
        {
          name: "Camping Les Oliviers",
          type: "camp_site",
          lat: 44.5,
          lon: 4.38,
          estimatedPriceMin: 12,
          estimatedPriceMax: 18,
          isExactPrice: false,
        },
        {
          name: "Hotel du Pont",
          type: "hotel",
          lat: 44.51,
          lon: 4.39,
          estimatedPriceMin: 65,
          estimatedPriceMax: 85,
          isExactPrice: false,
        },
      ],
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

export function stageGpxReadyEvent(stageIndex: number): MercureEvent {
  return {
    type: "stage_gpx_ready",
    data: {
      stageIndex,
      gpxContent: `<?xml version="1.0"?><gpx><trk><trkseg><trkpt lat="44.7" lon="4.5"><ele>280</ele></trkpt></trkseg></trk></gpx>`,
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
    stageGpxReadyEvent(0),
    stageGpxReadyEvent(1),
    stageGpxReadyEvent(2),
    tripCompleteEvent(),
  ];
}
