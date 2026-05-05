"use client";

import { useCallback, useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Loader2, AlertTriangle } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

/**
 * Reusable destructive confirmation dialog (sprint 27, issue #402).
 *
 * Used for high-risk actions that cannot be undone — such as deleting a trip
 * or an account. When `confirmationKeyword` is provided, the user must type
 * the keyword exactly into a text field before the destructive button is
 * enabled (used for account deletion). Otherwise the destructive button is
 * always active and the dialog is a simple "Cancel / Delete" confirmation.
 */
export interface DestructiveDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Title — must be explicit ("Delete this trip?"). */
  title: string;
  /** Description — explains the consequences of the action. */
  description: React.ReactNode;
  /**
   * If provided, user must type this keyword (case-sensitive) before the
   * destructive button activates. Used for highest-risk actions.
   */
  confirmationKeyword?: string;
  /** Label for the confirmation input (only shown when keyword is set). */
  confirmationLabel?: string;
  /** Placeholder for the confirmation input. */
  confirmationPlaceholder?: string;
  /** Custom label for the cancel button. Defaults to translated "Cancel". */
  cancelLabel?: string;
  /** Custom label for the destructive button. Defaults to translated "Delete". */
  confirmLabel?: string;
  /** Called when the user confirms. May be async. */
  onConfirm: () => void | Promise<void>;
  /** When true, the destructive button shows a spinner and is disabled. */
  isLoading?: boolean;
  /** Optional test id to discriminate dialog instances. */
  "data-testid"?: string;
}

export function DestructiveDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmationKeyword,
  confirmationLabel,
  confirmationPlaceholder,
  cancelLabel,
  confirmLabel,
  onConfirm,
  isLoading = false,
  "data-testid": testId = "destructive-dialog",
}: DestructiveDialogProps) {
  const t = useTranslations("destructiveDialog");
  const [typed, setTyped] = useState("");

  // Reset the typed keyword whenever the dialog opens or closes so the
  // confirmation can't be bypassed by reopening.
  useEffect(() => {
    if (!open) setTyped("");
  }, [open]);

  const requiresKeyword =
    typeof confirmationKeyword === "string" && confirmationKeyword.length > 0;
  const keywordSatisfied = !requiresKeyword || typed === confirmationKeyword;

  const handleConfirm = useCallback(() => {
    if (!keywordSatisfied || isLoading) return;
    void onConfirm();
  }, [keywordSatisfied, isLoading, onConfirm]);

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (isLoading) return;
        onOpenChange(next);
      }}
    >
      <DialogContent data-testid={testId}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <AlertTriangle
              className="h-5 w-5 text-destructive shrink-0"
              aria-hidden="true"
            />
            {title}
          </DialogTitle>
          <DialogDescription asChild>
            <div className="space-y-2 text-muted-foreground text-sm">
              {typeof description === "string" ? (
                <p>{description}</p>
              ) : (
                description
              )}
            </div>
          </DialogDescription>
        </DialogHeader>

        {requiresKeyword && (
          <div className="flex flex-col gap-2">
            <label
              htmlFor={`${testId}-keyword-input`}
              className="text-sm font-medium text-foreground"
            >
              {confirmationLabel ??
                t("typeKeyword", { keyword: confirmationKeyword })}
            </label>
            <Input
              id={`${testId}-keyword-input`}
              type="text"
              autoComplete="off"
              autoCorrect="off"
              spellCheck={false}
              value={typed}
              onChange={(e) => setTyped(e.target.value)}
              placeholder={confirmationPlaceholder ?? confirmationKeyword}
              disabled={isLoading}
              className={cn(
                keywordSatisfied
                  ? "ring-1 ring-green-500/60 dark:ring-green-400/50"
                  : "ring-2 ring-destructive/40",
              )}
              data-testid={`${testId}-keyword-input`}
              aria-invalid={
                typed.length > 0 && !keywordSatisfied ? true : undefined
              }
            />
          </div>
        )}

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isLoading}
            data-testid={`${testId}-cancel`}
          >
            {cancelLabel ?? t("cancel")}
          </Button>
          <Button
            variant="destructive"
            onClick={handleConfirm}
            disabled={!keywordSatisfied || isLoading}
            data-testid={`${testId}-confirm`}
          >
            {isLoading && (
              <Loader2
                className="h-4 w-4 mr-2 animate-spin"
                aria-hidden="true"
              />
            )}
            {confirmLabel ?? t("confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
