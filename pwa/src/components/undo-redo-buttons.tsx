"use client";

import { Undo2, Redo2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { useUndoRedo } from "@/hooks/use-undo-redo";

/**
 * Undo / Redo toolbar buttons.
 *
 * Renders two icon buttons that call `undo()` and `redo()` from the trip
 * temporal store.  Buttons are disabled when there is no history to traverse.
 *
 * Keyboard shortcuts (Ctrl+Z / Ctrl+Y / Ctrl+Shift+Z) are registered globally
 * by the `useUndoRedo` hook — they work regardless of whether these buttons
 * are visible on screen.
 */
export function UndoRedoButtons() {
  const t = useTranslations("undoRedo");
  const { canUndo, canRedo, undo, redo } = useUndoRedo();

  return (
    <div className="flex items-center gap-1">
      <Button
        variant="ghost"
        size="icon"
        className="h-9 w-9 cursor-pointer"
        onClick={undo}
        disabled={!canUndo}
        title={t("undoTitle")}
        aria-label={t("undoLabel")}
        data-testid="undo-button"
      >
        <Undo2 className="h-4 w-4" />
      </Button>
      <Button
        variant="ghost"
        size="icon"
        className="h-9 w-9 cursor-pointer"
        onClick={redo}
        disabled={!canRedo}
        title={t("redoTitle")}
        aria-label={t("redoLabel")}
        data-testid="redo-button"
      >
        <Redo2 className="h-4 w-4" />
      </Button>
    </div>
  );
}
