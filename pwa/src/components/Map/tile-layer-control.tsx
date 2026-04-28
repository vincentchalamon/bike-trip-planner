"use client";

import { useTranslations } from "next-intl";
import type { TileMode } from "@/hooks/use-tile-mode";

interface TileLayerControlProps {
  /** Current tile mode (controlled). */
  value: TileMode;
  /** Called when the user picks a different mode. */
  onChange: (mode: TileMode) => void;
  /** Optional extra class for the wrapping element. */
  className?: string;
}

const MODES: TileMode[] = ["map", "satellite"];

/**
 * Pill-style toggle that lets the user switch the trip map between an OSM
 * basemap and an Esri WorldImagery satellite layer. The selected pill carries
 * `aria-pressed=true` for screen readers; arrow keys / Home / End move the
 * focus between the pills (radiogroup-like behaviour) for keyboard users.
 *
 * The control is purely presentational — persistence (localStorage) and the
 * actual maplibre style switch happen in {@link MapView} via the
 * `useTileMode` hook.
 */
export function TileLayerControl({
  value,
  onChange,
  className,
}: TileLayerControlProps) {
  const t = useTranslations("map.tileLayer");

  function handleKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
    const currentIndex = MODES.indexOf(value);
    if (currentIndex === -1) return;

    let nextIndex = currentIndex;
    switch (event.key) {
      case "ArrowRight":
      case "ArrowDown":
        nextIndex = (currentIndex + 1) % MODES.length;
        break;
      case "ArrowLeft":
      case "ArrowUp":
        nextIndex = (currentIndex - 1 + MODES.length) % MODES.length;
        break;
      case "Home":
        nextIndex = 0;
        break;
      case "End":
        nextIndex = MODES.length - 1;
        break;
      default:
        return;
    }

    event.preventDefault();
    const nextMode = MODES[nextIndex];
    if (nextMode && nextMode !== value) {
      onChange(nextMode);
    }
    // Move keyboard focus to the newly-selected pill.
    const target = event.currentTarget.querySelector<HTMLButtonElement>(
      `[data-tile-mode="${nextMode}"]`,
    );
    target?.focus();
  }

  return (
    <div
      role="group"
      aria-label={t("groupLabel")}
      onKeyDown={handleKeyDown}
      className={[
        "inline-flex items-center gap-0.5 rounded-lg border border-border",
        "bg-[var(--surface)]/95 backdrop-blur-sm p-0.5 shadow-sm",
        className ?? "",
      ].join(" ")}
      data-testid="tile-layer-control"
    >
      {MODES.map((mode) => {
        const isActive = mode === value;
        return (
          <button
            key={mode}
            type="button"
            role="radio"
            aria-checked={isActive}
            aria-pressed={isActive}
            data-tile-mode={mode}
            data-testid={`tile-layer-${mode}`}
            tabIndex={isActive ? 0 : -1}
            onClick={() => onChange(mode)}
            className={[
              "cursor-pointer rounded-md px-3 py-1.5 text-xs font-sans font-medium",
              "transition-colors",
              "focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring)]",
              isActive
                ? "bg-[var(--brand)] text-white shadow-sm"
                : "text-[var(--ink)] hover:bg-[var(--accent)]",
            ].join(" ")}
          >
            {t(mode)}
          </button>
        );
      })}
    </div>
  );
}
