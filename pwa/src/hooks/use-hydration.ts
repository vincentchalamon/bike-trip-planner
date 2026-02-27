"use client";

import { useTripStore } from "@/store/trip-store";

export function useHydration(): boolean {
  return useTripStore((s) => s.hasHydrated);
}
