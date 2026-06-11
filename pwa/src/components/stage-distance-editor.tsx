"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Check, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

interface StageDistanceEditorProps {
  initialDistance: number | null;
  onCommit: (km: number) => void;
  onCancel: () => void;
}

export function StageDistanceEditor({
  initialDistance,
  onCommit,
  onCancel,
}: StageDistanceEditorProps) {
  const t = useTranslations("stage");
  const [editValue, setEditValue] = useState(
    initialDistance !== null ? String(Math.round(initialDistance)) : "",
  );

  function commitDistance() {
    const km = parseFloat(editValue);
    if (!isNaN(km) && km > 0) {
      onCommit(km);
    }
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter") {
      commitDistance();
    } else if (e.key === "Escape") {
      onCancel();
    }
  }

  // Compact, wrap-friendly layout so the editor isn't truncated inside the
  // narrow stat cell (recette #649): a fixed-width number input with a trailing
  // "km" suffix, plus confirm/cancel icon buttons that wrap below if needed.
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <div className="relative">
        <Input
          type="number"
          inputMode="numeric"
          value={editValue}
          onChange={(e) => setEditValue(e.target.value)}
          onKeyDown={handleKeyDown}
          className="h-8 w-24 pr-8 text-sm tabular-nums"
          min={1}
          aria-label={t("distanceLabel")}
          autoFocus
        />
        <span className="pointer-events-none absolute inset-y-0 right-2 flex items-center text-xs text-muted-foreground">
          km
        </span>
      </div>
      <Button
        variant="default"
        size="icon"
        className="h-8 w-8 shrink-0 cursor-pointer"
        onClick={commitDistance}
        aria-label={t("saveDistance")}
        title={t("saveDistance")}
      >
        <Check className="h-3.5 w-3.5" />
      </Button>
      <Button
        variant="outline"
        size="icon"
        className="h-8 w-8 shrink-0 cursor-pointer"
        onClick={onCancel}
        aria-label={t("cancelDistance")}
        title={t("cancelDistance")}
      >
        <X className="h-3.5 w-3.5" />
      </Button>
    </div>
  );
}
