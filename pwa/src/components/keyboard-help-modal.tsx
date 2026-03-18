"use client";

import { useTranslations } from "next-intl";
import { Keyboard } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { useUiStore } from "@/store/ui-store";

/**
 * Displays a modal listing all available keyboard shortcuts.
 *
 * The modal is opened/closed via the `isHelpModalOpen` flag in the UI store,
 * which is toggled by pressing `?` (via `useKeyboardShortcuts`) or by clicking
 * the "Aide" button in the toolbar.
 */
export function KeyboardHelpModal() {
  const t = useTranslations("keyboardHelp");
  const isOpen = useUiStore((s) => s.isHelpModalOpen);
  const setHelpModalOpen = useUiStore((s) => s.setHelpModalOpen);

  return (
    <Dialog open={isOpen} onOpenChange={setHelpModalOpen}>
      <DialogContent className="sm:max-w-md" data-testid="keyboard-help-modal">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Keyboard className="h-5 w-5" />
            {t("title")}
          </DialogTitle>
          <DialogDescription>{t("description")}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          {/* Navigation shortcuts */}
          <section aria-labelledby="keyboard-help-nav-heading">
            <h3
              id="keyboard-help-nav-heading"
              className="text-sm font-semibold text-muted-foreground uppercase tracking-wide mb-2"
            >
              {t("sectionNavigation")}
            </h3>
            <ShortcutTable
              shortcuts={[
                { keys: ["J"], label: t("nextStage") },
                { keys: ["K"], label: t("previousStage") },
              ]}
            />
          </section>

          {/* History shortcuts */}
          <section aria-labelledby="keyboard-help-history-heading">
            <h3
              id="keyboard-help-history-heading"
              className="text-sm font-semibold text-muted-foreground uppercase tracking-wide mb-2"
            >
              {t("sectionHistory")}
            </h3>
            <ShortcutTable
              shortcuts={[
                { keys: ["Ctrl", "Z"], label: t("undo") },
                { keys: ["Ctrl", "Y"], label: t("redo") },
              ]}
            />
          </section>

          {/* Interface shortcuts */}
          <section aria-labelledby="keyboard-help-ui-heading">
            <h3
              id="keyboard-help-ui-heading"
              className="text-sm font-semibold text-muted-foreground uppercase tracking-wide mb-2"
            >
              {t("sectionInterface")}
            </h3>
            <ShortcutTable
              shortcuts={[
                { keys: ["?"], label: t("toggleHelp") },
                { keys: ["Esc"], label: t("closePanels") },
              ]}
            />
          </section>
        </div>
      </DialogContent>
    </Dialog>
  );
}

interface Shortcut {
  keys: string[];
  label: string;
}

interface ShortcutTableProps {
  shortcuts: Shortcut[];
}

function ShortcutTable({ shortcuts }: ShortcutTableProps) {
  return (
    <div className="space-y-1.5">
      {shortcuts.map((shortcut) => (
        <div
          key={shortcut.keys.join("+")}
          className="flex items-center justify-between gap-4"
        >
          <span className="text-sm">{shortcut.label}</span>
          <div className="flex items-center gap-1 shrink-0">
            {shortcut.keys.map((key, index) => (
              <span key={key} className="flex items-center gap-1">
                {index > 0 && (
                  <span className="text-xs text-muted-foreground">+</span>
                )}
                <kbd className="inline-flex items-center justify-center rounded border border-border bg-muted px-1.5 py-0.5 text-xs font-mono font-medium shadow-sm min-w-[1.5rem]">
                  {key}
                </kbd>
              </span>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
