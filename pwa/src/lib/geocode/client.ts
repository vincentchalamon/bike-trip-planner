import { apiFetch } from "@/lib/api/client";
import { API_URL } from "@/lib/constants";

export interface GeocodeResult {
  name: string;
  displayName: string;
  lat: number;
  lon: number;
  type: string;
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
