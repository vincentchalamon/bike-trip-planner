"use client";

import { useState, useCallback } from "react";
import { useTranslations } from "next-intl";
import { MapView } from "./MapView";
import { ElevationProfile } from "./ElevationProfile";
import "./map-markers.css";
import type { StageData } from "@/lib/validation/schemas";

interface MapPanelProps {
  focusedStageIndex: number | null;
  onStageClick: (stageIndex: number) => void;
  onResetView: () => void;
  stages?: StageData[];
}

export function MapPanel({
  focusedStageIndex,
  onStageClick,
  onResetView,
  stages,
}: MapPanelProps) {
  const t = useTranslations("map");

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
      <div className="flex-1 min-h-0">
        <MapView
          focusedStageIndex={focusedStageIndex}
          onStageClick={onStageClick}
          onResetView={onResetView}
          highlightCoordIndex={hoverCoordIndex}
          highlightStageIndex={hoverStageIndex}
          stages={stages}
        />
      </div>

      <ElevationProfile
        focusedStageIndex={focusedStageIndex}
        onHover={handleProfileHover}
        stages={stages}
      />
    </div>
  );
}
