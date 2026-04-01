import { apiFetch } from "@/lib/api/client";

const API_BASE = process.env.NEXT_PUBLIC_API_URL ?? "";

export interface GeocodeResult {
  name: string;
  displayName: string;
  lat: number;
  lon: number;
  type: string;
}

export async function searchPlaces(query: string): Promise<GeocodeResult[]> {
  const res = await apiFetch(
    `${API_BASE}/geocode/search?q=${encodeURIComponent(query)}&limit=5`,
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
    `${API_BASE}/geocode/reverse?lat=${lat}&lon=${lon}`,
  );
  if (!res.ok) return null;
  const data = (await res.json()) as { results: GeocodeResult[] };
  return data.results[0] ?? null;
}
