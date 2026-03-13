"use client";

import { useMemo } from "react";
import { useTranslations } from "next-intl";
import { Loader2, Info, ChevronRight } from "lucide-react";
import { AccommodationItem } from "@/components/accommodation-item";
import { AddAccommodationButton } from "@/components/add-accommodation-button";
import { Separator } from "@/components/ui/separator";
import { Button } from "@/components/ui/button";
import type { AccommodationData } from "@/lib/validation/schemas";
import {
  MAX_ACCOMMODATION_RADIUS_KM,
  ACCOMMODATION_RADIUS_STEP_KM,
  DEFAULT_ACCOMMODATION_RADIUS_KM,
} from "@/lib/accommodation-constants";

interface AccommodationPanelProps {
  accommodations: AccommodationData[];
  selectedAccommodation?: AccommodationData | null;
  onUpdate: (accIndex: number, data: Partial<AccommodationData>) => void;
  onRemove: (accIndex: number) => void;
  onAdd: () => void;
  onSelect?: (accIndex: number) => void;
  onDeselect?: () => void;
  onExpandRadius?: (currentRadiusKm: number) => void;
  newAccKey?: string | null;
  stageIndex?: number;
  onClearNewAcc?: () => void;
  isProcessing?: boolean;
  searchRadiusKm?: number;
}

export function AccommodationPanel({
  accommodations,
  selectedAccommodation,
  onUpdate,
  onRemove,
  onAdd,
  onSelect,
  onDeselect,
  onExpandRadius,
  newAccKey,
  stageIndex,
  onClearNewAcc,
  isProcessing,
  searchRadiusKm = DEFAULT_ACCOMMODATION_RADIUS_KM,
}: AccommodationPanelProps) {
  const t = useTranslations("accommodation");
  const newAccIndex =
    newAccKey && stageIndex !== undefined
      ? parseInt(newAccKey.split("-")[1] ?? "", 10)
      : null;

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
      .sort((a, b) => {
        // Keep newly added accommodation at the end
        if (a === newAccIndex) return 1;
        if (b === newAccIndex) return -1;
        return (
          (accommodations[a]?.estimatedPriceMin ?? 0) -
          (accommodations[b]?.estimatedPriceMin ?? 0)
        );
      });
  }, [accommodations, newAccIndex]);

  const nextRadiusKm = searchRadiusKm + ACCOMMODATION_RADIUS_STEP_KM;
  const canExpand = nextRadiusKm <= MAX_ACCOMMODATION_RADIUS_KM;
  const hasNoAccommodations = accommodations.length === 0;

  return (
    <div className="bg-muted/50 rounded-lg p-4">
      {hasNoAccommodations &&
        (isProcessing ? (
          <div className="flex items-center gap-2 text-xs text-muted-foreground mb-3">
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
            <span>{t("loading")}</span>
          </div>
        ) : (
          <div className="mb-3 space-y-2">
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
              <Info className="h-3.5 w-3.5 shrink-0" />
              <span>{t("noAccommodation", { radius: searchRadiusKm })}</span>
            </div>
            {canExpand && onExpandRadius && (
              <div className="flex flex-col gap-1 pl-5">
                <Button
                  variant="link"
                  size="sm"
                  className="h-auto p-0 text-xs text-primary justify-start"
                  onClick={() => onExpandRadius(searchRadiusKm)}
                >
                  <ChevronRight className="h-3 w-3 mr-1" />
                  {t("expandRadius", { radius: nextRadiusKm })}
                </Button>
                <p className="text-xs text-muted-foreground">
                  {t("suggestExpandTypes")}
                </p>
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
              isSelected={isAccommodationSelected(originalIndex)}
              onUpdate={(data) => {
                onUpdate(originalIndex, data);
                if (newAccKey === `${stageIndex}-${originalIndex}`) {
                  onClearNewAcc?.();
                }
              }}
              onRemove={() => onRemove(originalIndex)}
              onSelect={onSelect ? () => onSelect(originalIndex) : undefined}
              onDeselect={
                isAccommodationSelected(originalIndex) ? onDeselect : undefined
              }
              initialEditing={newAccKey === `${stageIndex}-${originalIndex}`}
            />
          </div>
        );
      })}
      {!hasNoAccommodations && canExpand && onExpandRadius && (
        <div className="mt-2">
          <Button
            variant="link"
            size="sm"
            className="h-auto p-0 text-xs text-primary"
            onClick={() => onExpandRadius(searchRadiusKm)}
          >
            <ChevronRight className="h-3 w-3 mr-1" />
            {t("expandRadius", { radius: nextRadiusKm })}
          </Button>
        </div>
      )}
      <div className={accommodations.length > 0 ? "mt-3" : ""}>
        <AddAccommodationButton onClick={onAdd} />
      </div>
    </div>
  );
}
