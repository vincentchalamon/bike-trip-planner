"use client";

import { useTripStore } from "@/store/trip-store";
import { Skeleton } from "@/components/ui/skeleton";

export function HydrationBoundary({ children }: { children: React.ReactNode }) {
  const hasHydrated = useTripStore((s) => s.hasHydrated);

  if (!hasHydrated) {
    return (
      <Skeleton className="h-screen w-full" data-testid="loading-spinner" />
    );
  }

  return <>{children}</>;
}
