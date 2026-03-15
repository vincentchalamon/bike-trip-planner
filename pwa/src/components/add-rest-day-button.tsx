"use client";

import { useTranslations } from "next-intl";
import { BedDouble } from "lucide-react";
import { Button } from "@/components/ui/button";

interface AddRestDayButtonProps {
  afterIndex: number;
  dayNumber: number;
  onClick: () => void;
  disabled?: boolean;
}

export function AddRestDayButton({
  afterIndex,
  dayNumber,
  onClick,
  disabled,
}: AddRestDayButtonProps) {
  const t = useTranslations("restDay");
  return (
    <Button
      variant="ghost"
      size="sm"
      className="text-muted-icon hover:text-foreground hover:bg-muted cursor-pointer"
      onClick={onClick}
      disabled={disabled}
      aria-label={t("ariaLabel", { dayNumber })}
      data-testid={`add-rest-day-button-${afterIndex}`}
    >
      <BedDouble className="h-4 w-4 mr-1" />
      {t("add")}
    </Button>
  );
}
