"use client";

import { useState } from "react";
import { RefreshCw } from "lucide-react";
import { EditableField } from "@/components/editable-field";
import { LocationCombobox } from "@/components/location-combobox";
import type { GeocodeResult } from "@/lib/geocode/client";
import { cn } from "@/lib/utils";

interface LocationFieldsProps {
  departureLabel: string;
  arrivalLabel: string;
  isLoop: boolean;
  onDepartureChange?: (result: GeocodeResult) => void;
  onArrivalChange?: (result: GeocodeResult) => void;
}

export function LocationFields({
  departureLabel,
  arrivalLabel,
  isLoop,
  onDepartureChange,
  onArrivalChange,
}: LocationFieldsProps) {
  const [editingField, setEditingField] = useState<
    "departure" | "arrival" | null
  >(null);

  return (
    <div className="flex gap-3">
      {/* Vertical connector */}
      <div className="flex flex-col items-center py-1" aria-hidden="true">
        <div className="w-2 h-2 rounded-full bg-brand shrink-0" />
        <div className="flex-1 w-0.5 bg-brand my-1 min-h-[20px]" />
        <div className="w-2 h-2 rounded-full bg-brand shrink-0" />
      </div>

      {/* Fields */}
      <div className="flex flex-col gap-2 min-w-0">
        <div className="flex items-center gap-2">
          {editingField === "departure" && onDepartureChange ? (
            <LocationCombobox
              value={departureLabel}
              onSelect={(result) => {
                onDepartureChange(result);
                setEditingField(null);
              }}
              onCancel={() => setEditingField(null)}
              placeholder="Search departure..."
              aria-label="Search departure location"
            />
          ) : (
            <EditableField
              value={departureLabel}
              onChange={() => onDepartureChange && setEditingField("departure")}
              className="text-sm font-medium"
              placeholder="Departure"
              aria-label="Departure location"
              data-testid="trip-departure"
            />
          )}
        </div>

        <div className="flex items-center gap-2">
          {editingField === "arrival" && onArrivalChange ? (
            <LocationCombobox
              value={arrivalLabel}
              onSelect={(result) => {
                onArrivalChange(result);
                setEditingField(null);
              }}
              onCancel={() => setEditingField(null)}
              placeholder="Search arrival..."
              aria-label="Search arrival location"
            />
          ) : (
            <>
              {isLoop && (
                <RefreshCw className="h-3.5 w-3.5 text-muted-icon shrink-0" />
              )}
              <EditableField
                value={arrivalLabel}
                onChange={() => onArrivalChange && setEditingField("arrival")}
                className={cn(
                  "text-sm font-medium",
                  isLoop && "text-muted-foreground/60",
                )}
                placeholder="Arrival"
                aria-label="Arrival location"
                data-testid="trip-arrival"
              />
            </>
          )}
        </div>
      </div>
    </div>
  );
}
