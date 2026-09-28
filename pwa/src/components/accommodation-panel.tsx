"use client";

import { useMemo, useState, useEffect, useRef } from "react";
import { useTranslations } from "next-intl";
import { Loader2, Info, ChevronRight } from "lucide-react";
import { AccommodationItem } from "@/components/accommodation-item";
import { AddAccommodationButton } from "@/components/add-accommodation-button";
import { ManualAccommodationForm } from "@/components/manual-accommodation-form";
import { Separator } from "@/components/ui/separator";
import { Button } from "@/components/ui/button";
import { useUiStore } from "@/store/ui-store";
import { useTripStore } from "@/store/trip-store";
import { useAccommodationMutations } from "@/hooks/use-accommodation-mutations";
import type { AccommodationData } from "@btp/core";
import {
  MAX_ACCOMMODATION_RADIUS_KM,
  ACCOMMODATION_RADIUS_STEP_KM,
  DEFAULT_ACCOMMODATION_RADIUS_KM,
} from "@btp/core/constants";

interface AccommodationPanelProps {
  accommodations: AccommodationData[];
  selectedAccommodation?: AccommodationData | null;
  stageIndex: number;
  searchRadiusKm?: number;
  onAccommodationHover?: (accIndex: number | null) => void;
  readOnly?: boolean;
}

export function AccommodationPanel({
  accommodations,
  selectedAccommodation,
  stageIndex,
  searchRadiusKm = DEFAULT_ACCOMMODATION_RADIUS_KM,
  onAccommodationHover,
  readOnly = false,
}: AccommodationPanelProps) {
  const t = useTranslations("accommodation");
  const {
    handleExpandAccommodationRadius,
    handleAddManualAccommodation,
    handleSelectAccommodation,
    handleDeselectAccommodation,
  } = useAccommodationMutations();
  const updateLocalAccommodation = useTripStore(
    (s) => s.updateLocalAccommodation,
  );
  const removeLocalAccommodation = useTripStore(
    (s) => s.removeLocalAccommodation,
  );
  const [showManualForm, setShowManualForm] = useState(false);
  const isAccommodationScanning = useUiStore((s) => s.isAccommodationScanning);
  // isExpanding: derived from state + prop — no effect needed.
  // expandingFromRadius stores the radius at click time; when the SSE delivers
  // the new radius (searchRadiusKm changes), the derived value becomes false.
  const [expandingFromRadius, setExpandingFromRadius] = useState<number | null>(
    null,
  );
  const isExpanding =
    expandingFromRadius !== null && searchRadiusKm === expandingFromRadius;

  // isRemoving: ref-based so the effect only mutates a ref (not state).
  // Derived: true only while the list is empty after a selected-remove click.
  const removingActiveRef = useRef(false);
  const isRemoving = removingActiveRef.current && accommodations.length === 0;

  // Reset the ref when a scan result arrives (even empty).
  // Ref mutation avoids a re-render here; the spinner clears on the next
  // re-render triggered by the SSE update that changed accommodations.
  useEffect(() => {
    removingActiveRef.current = false;
  }, [accommodations]);

  function isAccommodationSelected(originalIndex: number): boolean {
    if (!selectedAccommodation) return false;
    const acc = accommodations[originalIndex];
    if (!acc) return false;
    return (
      acc.lat === selectedAccommodation.lat &&
      acc.lon === selectedAccommodation.lon &&
      acc.name === selectedAccommodation.name
    );
  }

  const sortedIndices = useMemo(() => {
    return accommodations
      .map((_, i) => i)
      .sort(
        (a, b) =>
          (accommodations[a]?.distanceToEndPoint ?? 0) -
          (accommodations[b]?.distanceToEndPoint ?? 0),
      );
  }, [accommodations]);

  const nextRadiusKm = searchRadiusKm + ACCOMMODATION_RADIUS_STEP_KM;
  const canExpand =
    nextRadiusKm <= MAX_ACCOMMODATION_RADIUS_KM && !selectedAccommodation;
  const hasNoAccommodations = accommodations.length === 0;

  return (
    <div className="bg-muted/50 rounded-lg p-4">
      {hasNoAccommodations &&
        (isAccommodationScanning || isRemoving ? (
          <div
            className="flex items-center gap-2 text-xs text-muted-foreground mb-3"
            data-testid="accommodation-loading"
          >
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
            <span>{t("loading")}</span>
          </div>
        ) : (
          <div className="mb-3 space-y-2">
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
              <Info className="h-3.5 w-3.5 shrink-0" />
              <span>{t("noAccommodation", { radius: searchRadiusKm })}</span>
            </div>
            {!readOnly && canExpand && (
              <div className="flex flex-col gap-1 pl-5">
                {isExpanding ? (
                  <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                    <span>{t("loading")}</span>
                  </div>
                ) : (
                  <>
                    <Button
                      variant="outline"
                      size="xs"
                      className="self-start"
                      onClick={async () => {
                        setExpandingFromRadius(searchRadiusKm);
                        const ok = await handleExpandAccommodationRadius(
                          stageIndex,
                          searchRadiusKm,
                        );
                        if (!ok) setExpandingFromRadius(null);
                      }}
                    >
                      <ChevronRight className="h-3 w-3" />
                      {t("expandRadius", { radius: nextRadiusKm })}
                    </Button>
                    <p className="text-xs text-muted-foreground">
                      {t("suggestExpandTypes")}
                    </p>
                  </>
                )}
              </div>
            )}
          </div>
        ))}
      {sortedIndices.map((originalIndex, displayIndex) => {
        const acc = accommodations[originalIndex];
        if (!acc) return null;
        return (
          <div key={originalIndex}>
            {displayIndex > 0 && <Separator className="my-2" />}
            <AccommodationItem
              accommodation={acc}
              readOnly={readOnly}
              isSelected={isAccommodationSelected(originalIndex)}
              onUpdate={(data) =>
                updateLocalAccommodation(stageIndex, originalIndex, data)
              }
              onRemove={() => {
                if (isAccommodationSelected(originalIndex)) {
                  removingActiveRef.current = true;
                }
                removeLocalAccommodation(stageIndex, originalIndex);
              }}
              onSelect={() =>
                handleSelectAccommodation(stageIndex, originalIndex)
              }
              onDeselect={
                isAccommodationSelected(originalIndex)
                  ? () => handleDeselectAccommodation(stageIndex)
                  : undefined
              }
              onHoverStart={
                onAccommodationHover
                  ? () => onAccommodationHover(originalIndex)
                  : undefined
              }
              onHoverEnd={
                onAccommodationHover
                  ? () => onAccommodationHover(null)
                  : undefined
              }
            />
          </div>
        );
      })}
      {!hasNoAccommodations && (isExpanding || isAccommodationScanning) && (
        <div
          className="mt-2 flex items-center gap-2 text-xs text-muted-foreground"
          data-testid="accommodation-loading"
        >
          <Loader2 className="h-3.5 w-3.5 animate-spin" />
          <span>{t("loading")}</span>
        </div>
      )}
      {!readOnly && !hasNoAccommodations && canExpand && !isExpanding && (
        <div className="mt-2">
          <Button
            variant="outline"
            size="xs"
            onClick={async () => {
              setExpandingFromRadius(searchRadiusKm);
              const ok = await handleExpandAccommodationRadius(
                stageIndex,
                searchRadiusKm,
              );
              if (!ok) setExpandingFromRadius(null);
            }}
          >
            <ChevronRight className="h-3 w-3" />
            {t("expandRadius", { radius: nextRadiusKm })}
          </Button>
        </div>
      )}
      {!readOnly && !selectedAccommodation && (
        <div className={accommodations.length > 0 ? "mt-3" : ""}>
          {showManualForm ? (
            <ManualAccommodationForm
              onSubmit={async (data) => {
                const ok = await handleAddManualAccommodation(stageIndex, data);
                if (ok) setShowManualForm(false);
                return ok;
              }}
              onCancel={() => setShowManualForm(false)}
            />
          ) : (
            <AddAccommodationButton onClick={() => setShowManualForm(true)} />
          )}
        </div>
      )}
    </div>
  );
}
