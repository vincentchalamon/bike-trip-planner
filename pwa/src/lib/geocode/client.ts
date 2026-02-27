export interface GeocodeResult {
  name: string;
  displayName: string;
  lat: number;
  lon: number;
  type: string;
}

export async function searchPlaces(query: string): Promise<GeocodeResult[]> {
  const res = await fetch(
    `/geocode/search?q=${encodeURIComponent(query)}&limit=5`,
  );
  if (!res.ok) return [];
  const data = (await res.json()) as { results: GeocodeResult[] };
  return data.results;
}

export async function reverseGeocode(
  lat: number,
  lon: number,
): Promise<GeocodeResult | null> {
  const res = await fetch(`/geocode/reverse?lat=${lat}&lon=${lon}`);
  if (!res.ok) return null;
  return (await res.json()) as GeocodeResult;
}
