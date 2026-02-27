"use client";

import { AccommodationItem } from "@/components/accommodation-item";
import { AddAccommodationButton } from "@/components/add-accommodation-button";
import { Separator } from "@/components/ui/separator";
import type { AccommodationData } from "@/lib/validation/schemas";

interface AccommodationPanelProps {
  accommodations: AccommodationData[];
  onUpdate: (accIndex: number, data: Partial<AccommodationData>) => void;
  onRemove: (accIndex: number) => void;
  onAdd: () => void;
}

export function AccommodationPanel({
  accommodations,
  onUpdate,
  onRemove,
  onAdd,
}: AccommodationPanelProps) {
  return (
    <div className="bg-muted/50 rounded-lg p-4">
      {accommodations.map((acc, index) => (
        <div key={index}>
          {index > 0 && <Separator className="my-2" />}
          <AccommodationItem
            accommodation={acc}
            onUpdate={(data) => onUpdate(index, data)}
            onRemove={() => onRemove(index)}
          />
        </div>
      ))}
      <div className={accommodations.length > 0 ? "mt-3" : ""}>
        <AddAccommodationButton onClick={onAdd} />
      </div>
    </div>
  );
}
