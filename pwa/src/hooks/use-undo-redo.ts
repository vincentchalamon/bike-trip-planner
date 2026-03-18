"use client";

import { useEffect } from "react";
import { useTripTemporalStore } from "@/store/trip-store";

/**
 * Provides undo/redo state and keyboard shortcut bindings for the trip store.
 *
 * Registers Ctrl+Z (undo) and Ctrl+Y / Ctrl+Shift+Z (redo) as global
 * document-level keyboard listeners.  The listeners are cleaned up on unmount.
 *
 * Returns the `canUndo`, `canRedo`, `undo`, and `redo` values so that
 * components can render toolbar buttons accordingly.
 */
export function useUndoRedo() {
  const canUndo = useTripTemporalStore((s) => s.canUndo);
  const canRedo = useTripTemporalStore((s) => s.canRedo);
  const undo = useTripTemporalStore((s) => s.undo);
  const redo = useTripTemporalStore((s) => s.redo);

  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      // Ignore events when the user is typing in an input/textarea/contentEditable
      const target = e.target as HTMLElement;
      const isEditing =
        target.tagName === "INPUT" ||
        target.tagName === "TEXTAREA" ||
        target.isContentEditable;
      if (isEditing) return;

      const ctrl = e.ctrlKey || e.metaKey;

      if (ctrl && !e.shiftKey && e.key === "z") {
        e.preventDefault();
        undo();
      } else if (
        ctrl &&
        (e.key === "y" ||
          (e.shiftKey && e.key === "z") ||
          (e.shiftKey && e.key === "Z"))
      ) {
        e.preventDefault();
        redo();
      }
    }

    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [undo, redo]);

  return { canUndo, canRedo, undo, redo };
}
