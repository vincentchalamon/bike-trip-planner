import { z } from "zod";

export const CoordinateSchema = z.object({
  lat: z.number(),
  lon: z.number(),
  ele: z.number().default(0),
});

export const AlertSchema = z.object({
  type: z.enum(["critical", "warning", "nudge"]),
  message: z.string(),
  lat: z.number().nullable().optional(),
  lon: z.number().nullable().optional(),
  source: z.string().optional(),
});

export const WeatherForecastSchema = z.object({
  icon: z.string(),
  description: z.string(),
  tempMin: z.number(),
  tempMax: z.number(),
  windSpeed: z.number(),
  windDirection: z.string(),
  precipitationProbability: z.number(),
});

export const PointOfInterestSchema = z.object({
  name: z.string(),
  category: z.string(),
  lat: z.number(),
  lon: z.number(),
  distanceFromStart: z.number().nullable().optional(),
});

export const AccommodationSchema = z.object({
  name: z.string(),
  type: z.string(),
  lat: z.number(),
  lon: z.number(),
  estimatedPriceMin: z.number(),
  estimatedPriceMax: z.number(),
  isExactPrice: z.boolean(),
  url: z.string().nullable().optional(),
  possibleClosed: z.boolean().default(false),
  distanceToEndPoint: z.number().default(0),
});

export const StageDataSchema = z.object({
  dayNumber: z.number(),
  distance: z.number(),
  elevation: z.number(),
  elevationLoss: z.number().default(0),
  startPoint: CoordinateSchema,
  endPoint: CoordinateSchema,
  geometry: z.array(CoordinateSchema),
  label: z.string().nullable(),
  startLabel: z.string().nullable(),
  endLabel: z.string().nullable(),
  weather: WeatherForecastSchema.nullable(),
  alerts: z.array(AlertSchema),
  pois: z.array(PointOfInterestSchema),
  accommodations: z.array(AccommodationSchema),
  selectedAccommodation: AccommodationSchema.nullable().optional(),
});

export const TripStateSchema = z.object({
  trip: z
    .object({
      id: z.string(),
      title: z.string(),
      sourceUrl: z.string(),
    })
    .nullable(),
  totalDistance: z.number().nullable(),
  totalElevation: z.number().nullable(),
  sourceType: z.string().nullable(),
  startDate: z.string().nullable(),
  endDate: z.string().nullable(),
  fatigueFactor: z.number().min(0.5).max(1.0).default(0.9),
  elevationPenalty: z.number().positive().default(50),
  ebikeMode: z.boolean().default(false),
  departureHour: z.number().int().min(0).max(23).default(8),
  stages: z.array(StageDataSchema),
  computationStatus: z.record(z.string(), z.string()),
});

export type CoordinateData = z.infer<typeof CoordinateSchema>;
export type AlertData = z.infer<typeof AlertSchema>;
export type WeatherData = z.infer<typeof WeatherForecastSchema>;
export type PoiData = z.infer<typeof PointOfInterestSchema>;
export type AccommodationData = z.infer<typeof AccommodationSchema>;
export type StageData = z.infer<typeof StageDataSchema>;
