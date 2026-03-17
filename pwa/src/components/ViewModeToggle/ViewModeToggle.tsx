"use client";

import { useEffect } from "react";
import { useTranslations } from "next-intl";
import { LayoutList, Map, LayoutPanelLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useUiStore, type ViewMode } from "@/store/ui-store";

/** Breakpoint in pixels below which we default to timeline-only mode. */
const MOBILE_BREAKPOINT = 1024;

/**
 * ViewModeToggle — three-button toggle for the trip planner layout:
 * - timeline: timeline only
 * - map: map only
 * - split: timeline + map side by side
 *
 * On first mount, defaults to "timeline" on mobile (< lg) and "split" on desktop.
 * The user can override at any time; the choice is kept in the Zustand UI store.
 */
export function ViewModeToggle() {
  const t = useTranslations("viewMode");
  const viewMode = useUiStore((s) => s.viewMode);
  const setViewMode = useUiStore((s) => s.setViewMode);

  // Set mobile default on first render (CSR only — no window on server).
  useEffect(() => {
    if (window.innerWidth < MOBILE_BREAKPOINT) {
      setViewMode("timeline");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Switch from split to timeline when window shrinks below the breakpoint.
  useEffect(() => {
    function handleResize() {
      if (window.innerWidth < MOBILE_BREAKPOINT && viewMode === "split") {
        setViewMode("timeline");
      }
    }
    window.addEventListener("resize", handleResize);
    return () => window.removeEventListener("resize", handleResize);
  }, [viewMode, setViewMode]);

  const modes: { value: ViewMode; icon: React.ReactNode; label: string }[] = [
    {
      value: "timeline",
      icon: <LayoutList className="h-4 w-4" />,
      label: t("timeline"),
    },
    {
      value: "split",
      icon: <LayoutPanelLeft className="h-4 w-4" />,
      label: t("split"),
    },
    {
      value: "map",
      icon: <Map className="h-4 w-4" />,
      label: t("map"),
    },
  ];

  return (
    <div
      className="flex items-center gap-1 rounded-md border p-0.5 bg-muted"
      role="group"
      aria-label={t("ariaLabel")}
      data-testid="view-mode-toggle"
    >
      {modes.map(({ value, icon, label }) => (
        <Button
          key={value}
          variant={viewMode === value ? "default" : "ghost"}
          size="sm"
          className={[
            "h-7 px-2 cursor-pointer",
            value === "split" ? "hidden lg:inline-flex" : "",
          ].join(" ")}
          onClick={() => setViewMode(value)}
          aria-pressed={viewMode === value}
          aria-label={label}
          data-testid={`view-mode-${value}`}
        >
          {icon}
          <span className="sr-only">{label}</span>
        </Button>
      ))}
    </div>
  );
}
