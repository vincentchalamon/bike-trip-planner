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
      variant="outline"
      className="w-full md:max-w-[80%] border-dashed border-muted-icon bg-muted/30 text-muted-icon hover:text-foreground hover:border-brand hover:bg-muted/50 cursor-pointer"
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
