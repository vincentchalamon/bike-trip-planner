"use client";

import { X, Link2, Euro } from "lucide-react";
import { EditableField } from "@/components/editable-field";
import { Button } from "@/components/ui/button";
import type { AccommodationData } from "@/lib/validation/schemas";

interface AccommodationItemProps {
  accommodation: AccommodationData;
  onUpdate: (data: Partial<AccommodationData>) => void;
  onRemove: () => void;
}

export function AccommodationItem({
  accommodation,
  onUpdate,
  onRemove,
}: AccommodationItemProps) {
  return (
    <div className="relative group py-2">
      <Button
        variant="ghost"
        size="icon"
        className="absolute top-2 right-0 h-6 w-6 text-muted-icon opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity"
        onClick={onRemove}
        aria-label="Remove accommodation"
      >
        <X className="h-3.5 w-3.5" />
      </Button>

      <EditableField
        value={accommodation.name}
        onChange={(name) => onUpdate({ name })}
        className="font-semibold text-sm"
        placeholder="Accommodation name"
        aria-label="Accommodation name"
      />

      <div className="flex items-center gap-1.5 mt-1">
        <Link2 className="h-3.5 w-3.5 text-muted-icon shrink-0" />
        <EditableField
          value={accommodation.type}
          onChange={(type) => onUpdate({ type })}
          className="text-sm text-muted-foreground"
          placeholder="Link or type"
          aria-label="Accommodation link"
        />
      </div>

      <div className="flex items-center gap-1.5 mt-1">
        <Euro className="h-3.5 w-3.5 text-muted-icon shrink-0" />
        <span className="text-sm text-muted-foreground">
          {accommodation.estimatedPriceMin}€ - {accommodation.estimatedPriceMax}
          €{accommodation.isExactPrice && " (exact)"}
        </span>
      </div>
    </div>
  );
}
