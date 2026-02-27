"use client";

import { useState } from "react";
import { ArrowRight } from "lucide-react";
import { EditableField } from "@/components/editable-field";
import { LocationCombobox } from "@/components/location-combobox";
import type { GeocodeResult } from "@/lib/geocode/client";

interface StageLocationsProps {
  stageIndex: number;
  startLabel: string;
  endLabel: string;
  onStartChange?: (result: GeocodeResult) => void;
  onEndChange?: (result: GeocodeResult) => void;
}

export function StageLocations({
  stageIndex,
  startLabel,
  endLabel,
  onStartChange,
  onEndChange,
}: StageLocationsProps) {
  const [editingField, setEditingField] = useState<"start" | "end" | null>(
    null,
  );

  return (
    <div className="flex items-center gap-2 flex-wrap">
      {editingField === "start" && onStartChange ? (
        <LocationCombobox
          value={startLabel}
          onSelect={(result) => {
            onStartChange(result);
            setEditingField(null);
          }}
          onCancel={() => setEditingField(null)}
          placeholder="Search departure..."
          aria-label="Search stage departure"
        />
      ) : (
        <EditableField
          value={startLabel || "Unknown location"}
          onChange={() => onStartChange && setEditingField("start")}
          className="font-semibold text-sm"
          aria-label={`Stage ${stageIndex + 1} departure`}
          data-testid={`stage-${stageIndex + 1}-departure`}
        />
      )}

      <ArrowRight className="h-4 w-4 text-muted-icon shrink-0" />

      {editingField === "end" && onEndChange ? (
        <LocationCombobox
          value={endLabel}
          onSelect={(result) => {
            onEndChange(result);
            setEditingField(null);
          }}
          onCancel={() => setEditingField(null)}
          placeholder="Search arrival..."
          aria-label="Search stage arrival"
        />
      ) : (
        <EditableField
          value={endLabel || "Unknown location"}
          onChange={() => onEndChange && setEditingField("end")}
          className="font-semibold text-sm"
          aria-label={`Stage ${stageIndex + 1} arrival`}
          data-testid={`stage-${stageIndex + 1}-arrival`}
        />
      )}
    </div>
  );
}
