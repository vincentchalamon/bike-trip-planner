"use client";

import { useTranslations } from "next-intl";
import { Share2, Settings } from "lucide-react";
import { Button } from "@/components/ui/button";
import { UndoRedoButtons } from "@/components/undo-redo-buttons";
import { TripDownloads } from "@/components/trip-downloads";

/**
 * Trip-specific action toolbar (recette #649).
 *
 * Groups the per-trip controls — whole-trip GPX/FIT downloads, undo/redo,
 * share and the configuration drawer — that previously lived in the global
 * {@link TopBar}. They now sit on the same line as the trip title, aligned to
 * the right, so the global bar only carries app-wide controls.
 */
export function TripActions({
  tripId,
  tripTitle,
  onShare,
  onOpenConfig,
}: {
  tripId?: string;
  tripTitle?: string;
  onShare?: () => void;
  onOpenConfig?: () => void;
}) {
  const t = useTranslations();

  return (
    <div
      className="flex items-center gap-0.5 sm:gap-1"
      data-testid="trip-actions"
    >
      {tripId && <TripDownloads tripId={tripId} tripTitle={tripTitle ?? ""} />}

      <div className="hidden sm:flex">
        <UndoRedoButtons />
      </div>

      {onShare && tripId && (
        <Button
          variant="ghost"
          size="icon"
          className="h-9 w-9 cursor-pointer"
          onClick={onShare}
          title={t("share.title")}
          aria-label={t("share.title")}
          data-testid="share-button"
        >
          <Share2 className="h-4 w-4" />
        </Button>
      )}

      {onOpenConfig && (
        <Button
          variant="ghost"
          size="icon"
          className="h-9 w-9 cursor-pointer"
          onClick={onOpenConfig}
          title={t("config.open")}
          aria-label={t("config.open")}
          data-testid="config-open-button"
        >
          <Settings className="h-4 w-4" />
        </Button>
      )}
    </div>
  );
}
