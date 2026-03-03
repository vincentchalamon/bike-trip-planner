"use client";

import { useMemo } from "react";
import { useTranslations } from "next-intl";
import { Loader2, Info } from "lucide-react";
import { AccommodationItem } from "@/components/accommodation-item";
import { AddAccommodationButton } from "@/components/add-accommodation-button";
import { Separator } from "@/components/ui/separator";
import type { AccommodationData } from "@/lib/validation/schemas";

interface AccommodationPanelProps {
  accommodations: AccommodationData[];
  onUpdate: (accIndex: number, data: Partial<AccommodationData>) => void;
  onRemove: (accIndex: number) => void;
  onAdd: () => void;
  newAccKey?: string | null;
  stageIndex?: number;
  onClearNewAcc?: () => void;
  isProcessing?: boolean;
}

export function AccommodationPanel({
  accommodations,
  onUpdate,
  onRemove,
  onAdd,
  newAccKey,
  stageIndex,
  onClearNewAcc,
  isProcessing,
}: AccommodationPanelProps) {
  const t = useTranslations("accommodation");
  const newAccIndex =
    newAccKey && stageIndex !== undefined
      ? parseInt(newAccKey.split("-")[1] ?? "", 10)
      : null;

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

  return (
    <div className="bg-muted/50 rounded-lg p-4">
      {accommodations.length === 0 &&
        (isProcessing ? (
          <div className="flex items-center gap-2 text-xs text-muted-foreground mb-3">
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
            <span>{t("loading")}</span>
          </div>
        ) : (
          <div className="flex items-center gap-2 text-xs text-muted-foreground mb-3">
            <Info className="h-3.5 w-3.5" />
            <span>{t("noAccommodation")}</span>
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
              onUpdate={(data) => {
                onUpdate(originalIndex, data);
                if (newAccKey === `${stageIndex}-${originalIndex}`) {
                  onClearNewAcc?.();
                }
              }}
              onRemove={() => onRemove(originalIndex)}
              initialEditing={newAccKey === `${stageIndex}-${originalIndex}`}
            />
          </div>
        );
      })}
      <div className={accommodations.length > 0 ? "mt-3" : ""}>
        <AddAccommodationButton onClick={onAdd} />
      </div>
    </div>
  );
}
