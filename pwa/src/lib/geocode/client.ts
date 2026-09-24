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
  // `/geocode/search` is an API Platform collection now, not a hand-built controller
  // response: the payload is a Hydra collection, so the places are under `member`.
  // `/geocode/reverse` below is still the controller, and still answers `{ results }`.
  const data = (await res.json()) as { member: GeocodeResult[] };
  return data.member;
}

export async function reverseGeocode(
  lat: number,
  lon: number,
  signal?: AbortSignal,
): Promise<GeocodeResult | null> {
  const res = await apiFetch(
    `${API_URL}/geocode/reverse?lat=${lat}&lon=${lon}`,
    {
      signal,
    },
  );
  if (!res.ok) return null;
  const data = (await res.json()) as { results: GeocodeResult[] };
  return data.results[0] ?? null;
}
