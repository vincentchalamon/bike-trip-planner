import type { useUiStore } from "@/store/ui-store";

declare global {
  interface Window {
    /** Exposed in non-production environments only (NODE_ENV !== 'production'). */
    __zustand_ui_store?: typeof useUiStore;
  }
}
