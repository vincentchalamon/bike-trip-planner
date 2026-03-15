"use client";

import { useEffect, useRef } from "react";
import { useTranslations } from "next-intl";
import { X, Map, Languages, Copy, Share2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { Switch } from "@/components/ui/switch";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { ThemeToggle } from "@/components/theme-toggle";
import { PacingSettings } from "@/components/pacing-settings";
import { useUiStore } from "@/store/ui-store";
import { cn } from "@/lib/utils";
import {
  ACCOMMODATION_TYPES,
  type AccommodationType,
} from "@/lib/accommodation-types";

interface ConfigPanelProps {
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
  ebikeMode: boolean;
  enabledAccommodationTypes: AccommodationType[];
  onPacingUpdate: (
    fatigueFactor: number,
    elevationPenalty: number,
    maxDistancePerDay: number,
    averageSpeed: number,
  ) => void;
  onEbikeModeChange: (ebikeMode: boolean) => void;
  onAccommodationTypesChange: (types: AccommodationType[]) => void;
}

export function ConfigPanel({
  fatigueFactor,
  elevationPenalty,
  maxDistancePerDay,
  averageSpeed,
  ebikeMode,
  enabledAccommodationTypes,
  onPacingUpdate,
  onEbikeModeChange,
  onAccommodationTypesChange,
}: ConfigPanelProps) {
  const t = useTranslations("config");
  const tAccommodation = useTranslations("accommodation");
  const isOpen = useUiStore((s) => s.isConfigPanelOpen);
  const setConfigPanelOpen = useUiStore((s) => s.setConfigPanelOpen);
  const panelRef = useRef<HTMLDivElement>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);

  // Restore focus to the trigger element when panel closes
  useEffect(() => {
    if (isOpen) {
      previousFocusRef.current = document.activeElement as HTMLElement;
    } else {
      previousFocusRef.current?.focus();
    }
  }, [isOpen]);

  // Close on Escape key
  useEffect(() => {
    if (!isOpen) return;
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") {
        setConfigPanelOpen(false);
      }
    }
    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [isOpen, setConfigPanelOpen]);

  // Trap focus inside panel when open
  useEffect(() => {
    if (!isOpen) return;
    const panel = panelRef.current;
    if (!panel) return;
    const focusable = panel.querySelectorAll<HTMLElement>(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
    );
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    function handleTab(e: KeyboardEvent) {
      if (e.key !== "Tab") return;
      if (e.shiftKey) {
        if (document.activeElement === first) {
          e.preventDefault();
          last?.focus();
        }
      } else {
        if (document.activeElement === last) {
          e.preventDefault();
          first?.focus();
        }
      }
    }
    panel.focus();
    panel.addEventListener("keydown", handleTab);
    return () => panel.removeEventListener("keydown", handleTab);
  }, [isOpen]);

  function handleAccommodationTypeToggle(type: AccommodationType) {
    const isEnabled = enabledAccommodationTypes.includes(type);
    if (isEnabled) {
      // Keep at least one type enabled
      if (enabledAccommodationTypes.length <= 1) return;
      onAccommodationTypesChange(
        enabledAccommodationTypes.filter((existing) => existing !== type),
      );
    } else {
      onAccommodationTypesChange([...enabledAccommodationTypes, type]);
    }
  }

  return (
    <>
      {/* Backdrop */}
      {isOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/30 backdrop-blur-sm"
          aria-hidden="true"
          onClick={() => setConfigPanelOpen(false)}
        />
      )}

      {/* Slide-in panel */}
      <div
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-hidden={!isOpen}
        aria-label={t("title")}
        className={cn(
          "fixed top-0 right-0 z-50 h-full w-80 bg-background border-l shadow-xl",
          "flex flex-col overflow-hidden",
          "transition-transform duration-300 ease-in-out",
          "focus:outline-none",
          isOpen ? "translate-x-0" : "translate-x-full",
        )}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 border-b shrink-0">
          <h2 className="font-semibold text-base">{t("title")}</h2>
          <Button
            variant="ghost"
            size="icon"
            className="h-8 w-8"
            onClick={() => setConfigPanelOpen(false)}
            aria-label={t("close")}
          >
            <X className="h-4 w-4" />
          </Button>
        </div>

        {/* Scrollable content */}
        <div className="flex-1 overflow-y-auto px-4 py-4 space-y-6">
          {/* Cyclo profile / pacing settings */}
          <section aria-labelledby="config-pacing-heading">
            <h3 id="config-pacing-heading" className="text-sm font-medium mb-3">
              {t("pacingTitle")}
            </h3>
            <PacingSettings
              fatigueFactor={fatigueFactor}
              elevationPenalty={elevationPenalty}
              maxDistancePerDay={maxDistancePerDay}
              averageSpeed={averageSpeed}
              ebikeMode={ebikeMode}
              onUpdate={onPacingUpdate}
              onEbikeModeChange={onEbikeModeChange}
            />
          </section>

          <Separator />

          {/* Accommodation type filters */}
          <section aria-labelledby="config-accommodation-heading">
            <h3
              id="config-accommodation-heading"
              className="text-sm font-medium mb-3"
            >
              {t("accommodationTitle")}
            </h3>
            <div className="flex flex-col gap-2">
              {ACCOMMODATION_TYPES.map((type) => {
                const isEnabled = enabledAccommodationTypes.includes(type);
                const isLastEnabled =
                  isEnabled && enabledAccommodationTypes.length <= 1;
                return (
                  <div key={type} className="flex items-center gap-2">
                    <Switch
                      id={`acc-type-${type}`}
                      size="sm"
                      checked={isEnabled}
                      onCheckedChange={() =>
                        handleAccommodationTypeToggle(type)
                      }
                      disabled={isLastEnabled}
                      aria-label={tAccommodation(
                        `type_${type}` as Parameters<typeof tAccommodation>[0],
                      )}
                    />
                    <label
                      htmlFor={`acc-type-${type}`}
                      className={cn(
                        "text-sm cursor-pointer",
                        isLastEnabled && "opacity-50 cursor-not-allowed",
                      )}
                    >
                      {tAccommodation(
                        `type_${type}` as Parameters<typeof tAccommodation>[0],
                      )}
                    </label>
                  </div>
                );
              })}
            </div>
          </section>

          <Separator />

          {/* Theme */}
          <section aria-labelledby="config-theme-heading">
            <h3 id="config-theme-heading" className="text-sm font-medium mb-3">
              {t("themeTitle")}
            </h3>
            <div className="flex items-center gap-2">
              <ThemeToggle />
              <span className="text-sm text-muted-foreground">
                {t("themeDescription")}
              </span>
            </div>
          </section>

          <Separator />

          {/* Split view — placeholder (Sprint 7) */}
          <section aria-labelledby="config-splitview-heading">
            <h3
              id="config-splitview-heading"
              className="text-sm font-medium mb-3"
            >
              {t("splitViewTitle")}
            </h3>
            <Tooltip>
              <TooltipTrigger asChild>
                <div className="flex items-center gap-2 opacity-50">
                  <Switch
                    id="split-view-toggle"
                    size="sm"
                    checked={false}
                    disabled
                    aria-label={t("splitViewLabel")}
                  />
                  <label
                    htmlFor="split-view-toggle"
                    className="text-sm cursor-not-allowed flex items-center gap-1.5"
                  >
                    <Map className="h-3.5 w-3.5" />
                    {t("splitViewLabel")}
                  </label>
                </div>
              </TooltipTrigger>
              <TooltipContent>{t("splitViewTooltip")}</TooltipContent>
            </Tooltip>
          </section>

          <Separator />

          {/* Language — placeholder */}
          <section aria-labelledby="config-language-heading">
            <h3
              id="config-language-heading"
              className="text-sm font-medium mb-3"
            >
              {t("languageTitle")}
            </h3>
            <Tooltip>
              <TooltipTrigger asChild>
                <div className="flex items-center gap-2 opacity-50">
                  <Languages className="h-4 w-4 text-muted-foreground" />
                  <span className="text-sm">{t("languagePlaceholder")}</span>
                </div>
              </TooltipTrigger>
              <TooltipContent>{t("languageTooltip")}</TooltipContent>
            </Tooltip>
          </section>

          <Separator />

          {/* Future features: Duplication + Sharing */}
          <section aria-labelledby="config-future-heading">
            <h3 id="config-future-heading" className="text-sm font-medium mb-3">
              {t("tripActionsTitle")}
            </h3>
            <div className="flex flex-col gap-2">
              <Tooltip>
                <TooltipTrigger asChild>
                  <div>
                    <Button
                      variant="outline"
                      size="sm"
                      className="w-full justify-start gap-2 opacity-50 cursor-not-allowed"
                      disabled
                      aria-label={t("duplicateLabel")}
                    >
                      <Copy className="h-4 w-4" />
                      {t("duplicateLabel")}
                    </Button>
                  </div>
                </TooltipTrigger>
                <TooltipContent>{t("duplicateTooltip")}</TooltipContent>
              </Tooltip>

              <Tooltip>
                <TooltipTrigger asChild>
                  <div>
                    <Button
                      variant="outline"
                      size="sm"
                      className="w-full justify-start gap-2 opacity-50 cursor-not-allowed"
                      disabled
                      aria-label={t("shareLabel")}
                    >
                      <Share2 className="h-4 w-4" />
                      {t("shareLabel")}
                    </Button>
                  </div>
                </TooltipTrigger>
                <TooltipContent>{t("shareTooltip")}</TooltipContent>
              </Tooltip>
            </div>
          </section>
        </div>
      </div>
    </>
  );
}
