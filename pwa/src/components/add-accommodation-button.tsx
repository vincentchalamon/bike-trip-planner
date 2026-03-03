"use client";

import { useTranslations } from "next-intl";
import { Plus } from "lucide-react";
import { Button } from "@/components/ui/button";

interface AddAccommodationButtonProps {
  onClick: () => void;
}

export function AddAccommodationButton({
  onClick,
}: AddAccommodationButtonProps) {
  const t = useTranslations("accommodation");
  return (
    <Button
      variant="outline"
      className="w-full border-dashed border-muted-icon text-muted-icon hover:text-foreground hover:border-brand cursor-pointer"
      onClick={onClick}
    >
      <Plus className="h-4 w-4 mr-1" />
      {t("add")}
    </Button>
  );
}
