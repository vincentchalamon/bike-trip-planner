"use client";

import { useTranslations } from "next-intl";
import { Plus } from "lucide-react";
import { Button } from "@/components/ui/button";

interface AddStageButtonProps {
  afterIndex: number;
  onClick: () => void;
  disabled?: boolean;
}

export function AddStageButton({
  afterIndex,
  onClick,
  disabled,
}: AddStageButtonProps) {
  const t = useTranslations("addStage");
  return (
    <Button
      variant="outline"
      className="w-full md:max-w-[80%] border-dashed border-muted-icon text-muted-icon hover:text-foreground hover:border-brand cursor-pointer"
      onClick={onClick}
      disabled={disabled}
      title={disabled ? t("insufficientSpace") : undefined}
      data-testid={`add-stage-button-${afterIndex}`}
    >
      <Plus className="h-4 w-4 mr-1" />
      {t("add")}
    </Button>
  );
}
