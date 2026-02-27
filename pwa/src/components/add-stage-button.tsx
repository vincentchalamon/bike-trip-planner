"use client";

import { Plus } from "lucide-react";
import { Button } from "@/components/ui/button";

interface AddStageButtonProps {
  afterIndex: number;
  onClick: () => void;
}

export function AddStageButton({ afterIndex, onClick }: AddStageButtonProps) {
  return (
    <Button
      variant="outline"
      className="w-full max-w-[80%] md:max-w-[80%] border-dashed border-muted-icon text-muted-icon hover:text-foreground ml-10 md:ml-16"
      onClick={onClick}
      data-testid={`add-stage-button-${afterIndex}`}
    >
      <Plus className="h-4 w-4 mr-1" />
      Add stage
    </Button>
  );
}
