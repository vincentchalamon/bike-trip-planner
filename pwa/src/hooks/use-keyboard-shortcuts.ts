"use client";

import { useEffect } from "react";
import { useUiStore } from "@/store/ui-store";

/**
 * Registers global keyboard shortcuts for the trip planner:
 *
 * - `Escape`: close the config panel or help modal (whichever is open)
 * - `?`: toggle the keyboard shortcuts help modal
 * - `j`: navigate to the next stage (increment `focusedMapStageIndex`)
 * - `k`: navigate to the previous stage (decrement `focusedMapStageIndex`)
 *
 * Undo/redo shortcuts (Ctrl+Z / Ctrl+Y) are handled separately by
 * `useUndoRedo` to keep temporal concerns isolated.
 *
 * All shortcuts are suppressed when the user is typing in an input,
 * textarea, select, or contentEditable element.
 *
 * @param stageCount - Total number of active (non-rest) stages, used to
 *   clamp the focused stage index for J/K navigation. Pass `0` when no
 *   trip is loaded.
 */
export function useKeyboardShortcuts(stageCount: number) {
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      // Ignore events when the user is typing
      const target = e.target as HTMLElement;
      const isEditing =
        target.tagName === "INPUT" ||
        target.tagName === "TEXTAREA" ||
        target.tagName === "SELECT" ||
        target.isContentEditable;
      if (isEditing) return;

      // Ignore modified keys (except Shift for ?) to avoid clashing with browser shortcuts
      const ctrl = e.ctrlKey || e.metaKey;
      if (ctrl || e.altKey) return;

      const {
        isConfigPanelOpen,
        setConfigPanelOpen,
        isHelpModalOpen,
        setHelpModalOpen,
        focusedMapStageIndex,
        setFocusedMapStageIndex,
      } = useUiStore.getState();

      switch (e.key) {
        case "Escape":
          // Close help modal first; fall through to config panel on next press
          if (isHelpModalOpen) {
            e.preventDefault();
            setHelpModalOpen(false);
          } else if (isConfigPanelOpen) {
            e.preventDefault();
            setConfigPanelOpen(false);
          }
          break;

        case "?":
          e.preventDefault();
          setHelpModalOpen(!isHelpModalOpen);
          break;

        case "j":
        case "J":
          if (stageCount === 0) break;
          e.preventDefault();
          if (focusedMapStageIndex === null) {
            setFocusedMapStageIndex(0);
          } else if (focusedMapStageIndex + 1 >= stageCount) {
            setFocusedMapStageIndex(null);
          } else {
            setFocusedMapStageIndex(focusedMapStageIndex + 1);
          }
          break;

        case "k":
        case "K":
          if (stageCount === 0) break;
          e.preventDefault();
          if (focusedMapStageIndex === null) {
            setFocusedMapStageIndex(stageCount - 1);
          } else if (focusedMapStageIndex - 1 < 0) {
            setFocusedMapStageIndex(null);
          } else {
            setFocusedMapStageIndex(focusedMapStageIndex - 1);
          }
          break;
      }
    }

    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [stageCount]);
}
