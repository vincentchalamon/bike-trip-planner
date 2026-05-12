import type { useUiStore } from "@/store/ui-store";
import type { useTripStore } from "@/store/trip-store";

declare global {
  interface Window {
    /** Exposed in non-production environments only (NODE_ENV !== 'production'). */
    __zustand_ui_store?: typeof useUiStore;
    /** Exposed in non-production environments only (NODE_ENV !== 'production'). */
    __zustand_trip_store?: typeof useTripStore;
  }
}
