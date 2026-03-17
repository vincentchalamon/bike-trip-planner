"use client";

import { useState, useCallback } from "react";
import { useTranslations } from "next-intl";
import { MapView } from "./MapView";
import { ElevationProfile } from "./ElevationProfile";
import "./map-markers.css";

interface MapPanelProps {
  /**
   * Controlled: which stage is currently focused (0-based index into active stages).
   * null = global view.
   */
  focusedStageIndex: number | null;
  onStageClick: (stageIndex: number) => void;
  onResetView: () => void;
}

/**
 * MapPanel composes MapView + ElevationProfile and manages
 * the bidirectional hover state between them:
 *
 * - ElevationProfile hover → cursor dot on MapView
 * - (future) Map route hover → highlight on ElevationProfile
 *
 * The panel itself is a simple flex column; sizing is left to the parent.
 */
export function MapPanel({
  focusedStageIndex,
  onStageClick,
  onResetView,
}: MapPanelProps) {
  const t = useTranslations("map");

  // Elevation profile ↔ map hover synchronization
  const [hoverCoordIndex, setHoverCoordIndex] = useState<number | null>(null);
  const [hoverStageIndex, setHoverStageIndex] = useState<number | null>(null);

  const handleProfileHover = useCallback(
    (coordIndex: number | null, stageIndex: number | null) => {
      setHoverCoordIndex(coordIndex);
      setHoverStageIndex(stageIndex);
    },
    [],
  );

  return (
    <div
      className="flex flex-col gap-2 w-full h-full"
      data-testid="map-panel"
      aria-label={t("panelAriaLabel")}
    >
      {/* Main map — grows to fill available space */}
      <div className="flex-1 min-h-0">
        <MapView
          focusedStageIndex={focusedStageIndex}
          onStageClick={onStageClick}
          onResetView={onResetView}
          highlightCoordIndex={hoverCoordIndex}
          highlightStageIndex={hoverStageIndex}
        />
      </div>

      {/* Elevation profile — fixed height at bottom */}
      <ElevationProfile
        focusedStageIndex={focusedStageIndex}
        onHover={handleProfileHover}
      />
    </div>
  );
}
