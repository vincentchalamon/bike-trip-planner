"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Check } from "lucide-react";
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

  return (
    <div className="flex items-center gap-2">
      <Input
        type="number"
        value={editValue}
        onChange={(e) => setEditValue(e.target.value)}
        onKeyDown={handleKeyDown}
        className="h-7 w-20 text-sm"
        min={1}
        aria-label={t("distanceLabel")}
        autoFocus
      />
      <span className="text-sm text-muted-foreground">km</span>
      <Button
        variant="outline"
        size="icon"
        className="h-7 w-7 cursor-pointer"
        onClick={commitDistance}
        aria-label={t("saveDistance")}
      >
        <Check className="h-3.5 w-3.5" />
      </Button>
    </div>
  );
}
