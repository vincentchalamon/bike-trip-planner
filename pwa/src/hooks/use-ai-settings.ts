"use client";

import { useEffect } from "react";
import { fetchAiSettings } from "@/lib/api/client";
import { useUiStore } from "@/store/ui-store";

/**
 * Sync the `configured` AI-capability signal from the account (ADR-042).
 *
 * Reads `GET /users/me/ai-settings` once on mount and flips
 * `aiCapability.configured` to whether a provider is set. AI surfaces stay
 * disabled-but-visible (with a "Configurez une IA" CTA) until this confirms a
 * configured provider. No network call at all when AI is disabled by build
 * config (`AI_ENABLED`).
 */
export function useAiSettings(): void {
  const aiEnabled = useUiStore((s) => s.aiCapability.enabled);
  const setAiConfigured = useUiStore((s) => s.setAiConfigured);

  useEffect(() => {
    if (!aiEnabled) return;
    let cancelled = false;
    void fetchAiSettings().then((settings) => {
      if (!cancelled) setAiConfigured(Boolean(settings?.provider));
    });
    return () => {
      cancelled = true;
    };
  }, [aiEnabled, setAiConfigured]);
}
