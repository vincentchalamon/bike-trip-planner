import type { useUiStore } from "@/store/ui-store";

declare global {
  interface Window {
    /** Exposed in all environments for E2E test access via Playwright evaluate. */
    __zustand_ui_store?: typeof useUiStore;
  }
}
