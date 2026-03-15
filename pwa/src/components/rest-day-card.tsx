"use client";

import { useTranslations } from "next-intl";
import { BedDouble } from "lucide-react";
import { Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";

interface RestDayCardProps {
  dayNumber: number;
  stageIndex: number;
  canDelete?: boolean;
  onDelete?: () => void;
}

export function RestDayCard({
  dayNumber,
  stageIndex,
  canDelete = true,
  onDelete,
}: RestDayCardProps) {
  const tRestDay = useTranslations("restDay");
  const tStage = useTranslations("stage");

  return (
    <div
      className="rounded-xl border border-dashed border-muted-foreground/30 bg-muted/30 p-4 flex items-center gap-3 w-full md:max-w-[80%]"
      data-testid={`rest-day-card-${stageIndex}`}
    >
      <BedDouble className="h-5 w-5 text-muted-foreground shrink-0" />
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium text-muted-foreground">
          {tRestDay("label")}
        </p>
      </div>
      {canDelete && onDelete && (
        <Button
          variant="ghost"
          size="icon"
          className="h-8 w-8 text-muted-foreground hover:text-destructive shrink-0"
          onClick={onDelete}
          aria-label={tStage("deleteStage", { dayNumber })}
          data-testid={`delete-rest-day-${stageIndex}`}
        >
          <Trash2 className="h-4 w-4" />
        </Button>
      )}
    </div>
  );
}
