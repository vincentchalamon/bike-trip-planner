"use client";

import { Plus } from "lucide-react";
import { Button } from "@/components/ui/button";

interface AddAccommodationButtonProps {
  onClick: () => void;
}

export function AddAccommodationButton({
  onClick,
}: AddAccommodationButtonProps) {
  return (
    <Button
      variant="outline"
      className="w-full border-dashed border-muted-icon text-muted-icon hover:text-foreground"
      onClick={onClick}
    >
      <Plus className="h-4 w-4 mr-1" />
      Add accommodation
    </Button>
  );
}
