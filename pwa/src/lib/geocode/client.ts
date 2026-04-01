import { apiFetch } from "@/lib/api/client";
import { API_URL } from "@/lib/constants";

export interface GeocodeResult {
  name: string;
  displayName: string;
  lat: number;
  lon: number;
  type: string;
}

export async function searchPlaces(query: string): Promise<GeocodeResult[]> {
  const res = await apiFetch(
    `${API_URL}/geocode/search?q=${encodeURIComponent(query)}&limit=5`,
  );
  if (!res.ok) return [];
  const data = (await res.json()) as { results: GeocodeResult[] };
  return data.results;
}

export async function reverseGeocode(
  lat: number,
  lon: number,
): Promise<GeocodeResult | null> {
  const res = await apiFetch(
    `${API_URL}/geocode/reverse?lat=${lat}&lon=${lon}`,
  );
  if (!res.ok) return null;
  const data = (await res.json()) as { results: GeocodeResult[] };
  return data.results[0] ?? null;
}
