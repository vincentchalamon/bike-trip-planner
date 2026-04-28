"use client";

import { useCallback, useId, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Sparkles, Loader2, Eraser, Send } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { cn } from "@/lib/utils";

/**
 * Maximum number of characters accepted in the suggestion textarea.
 * Mirrors the rough budget for a single-shot refinement prompt — long enough
 * to describe a structural change ("Ajoute une étape à Ajaccio puis remonte
 * vers Calvi par la côte ouest"), short enough to keep the UI focused.
 */
const MAX_SUGGESTION_LENGTH = 500;

interface AiRefinementCardProps {
  /**
   * Optional handler invoked when the user clicks "Appliquer".
   *
   * Stub for now — the chat-IA endpoint (#309) ships in sprint 31. When the
   * endpoint exists, the parent will pass a real handler that posts the
   * suggestion to the backend, regenerates the stages, and refreshes the
   * preview. Until then the button shows a transient "indisponible" state.
   *
   * The promise should resolve to `true` on success so we can clear the
   * textarea, `false` to keep the suggestion in place for retry.
   */
  onApply?: (suggestion: string) => Promise<boolean>;
  /**
   * When `true`, both buttons are disabled (e.g. during a parent-managed
   * recomputation or when the trip is locked / offline).
   */
  disabled?: boolean;
  className?: string;
}

/**
 * Acte 1.5 — single-shot AI refinement card (issue #393).
 *
 * Lives inside the Step 2 "Aperçu" of the `/trips/new` wizard, alongside the
 * trip preview. The user can describe a structural modification of the route
 * in plain language (e.g. "Ajoute une étape à Ajaccio") and apply it to
 * regenerate the stages without going back to Step 1.
 *
 * Unlike the chat card on Step 1 (Préparation), this card is **stateless** —
 * a single request at a time, no scrollable history. The textarea is the only
 * persistent state; "Effacer" wipes it, "Appliquer" submits then clears on
 * success.
 *
 * UI shell only — the wired-up endpoint (#309) is part of a later sprint.
 * TODO(#309): replace the stub `onApply` no-op with the real chat-IA call
 * once the endpoint ships.
 */
export function AiRefinementCard({
  onApply,
  disabled = false,
  className,
}: AiRefinementCardProps) {
  const t = useTranslations("aiRefinement");
  const textareaId = useId();
  const helperId = useId();
  const [suggestion, setSuggestion] = useState("");
  const [isApplying, setIsApplying] = useState(false);

  const trimmed = suggestion.trim();
  const remaining = MAX_SUGGESTION_LENGTH - suggestion.length;
  const canApply = trimmed.length > 0 && !isApplying && !disabled;
  const canClear = suggestion.length > 0 && !isApplying && !disabled;

  const handleClear = useCallback(() => {
    setSuggestion("");
  }, []);

  const handleApply = useCallback(async () => {
    if (!canApply) return;
    if (!onApply) {
      // TODO(#309): when the chat-IA endpoint ships (sprint 31), this branch
      // goes away — a real `onApply` handler will always be passed by the
      // wizard parent. Until then we surface a non-blocking toast so the user
      // knows the suggestion was registered but the assistant isn't live yet.
      toast.info(t("unavailable"));
      return;
    }
    setIsApplying(true);
    try {
      const ok = await onApply(trimmed);
      if (ok) {
        setSuggestion("");
      } else {
        toast.error(t("applyFailed"));
      }
    } catch {
      toast.error(t("applyFailed"));
    } finally {
      setIsApplying(false);
    }
  }, [canApply, onApply, t, trimmed]);

  return (
    <Card
      className={cn("border-brand/20 bg-brand-light/40", className)}
      data-testid="ai-refinement-card"
      aria-busy={isApplying}
    >
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Sparkles
            className="h-5 w-5 text-brand"
            aria-hidden="true"
            data-testid="ai-refinement-icon"
          />
          <span>{t("title")}</span>
        </CardTitle>
        <p
          id={helperId}
          className="text-sm text-muted-foreground"
          data-testid="ai-refinement-helper"
        >
          {t("helper")}
        </p>
      </CardHeader>
      <CardContent className="space-y-3">
        <label htmlFor={textareaId} className="sr-only">
          {t("textareaLabel")}
        </label>
        <textarea
          id={textareaId}
          value={suggestion}
          onChange={(e) => setSuggestion(e.target.value.slice(0, MAX_SUGGESTION_LENGTH))}
          rows={3}
          maxLength={MAX_SUGGESTION_LENGTH}
          placeholder={t("placeholder")}
          aria-describedby={helperId}
          disabled={disabled || isApplying}
          data-testid="ai-refinement-textarea"
          className={cn(
            "w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs",
            "placeholder:text-muted-foreground",
            "focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:border-ring",
            "disabled:cursor-not-allowed disabled:opacity-50",
            "min-h-[88px]",
          )}
        />
        <div className="flex items-center justify-between gap-2">
          <span
            className="text-xs text-muted-foreground tabular-nums"
            data-testid="ai-refinement-counter"
            aria-live="polite"
          >
            {t("charactersRemaining", { remaining })}
          </span>
          <div className="flex items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={handleClear}
              disabled={!canClear}
              aria-label={t("clearAria")}
              data-testid="ai-refinement-clear"
            >
              <Eraser className="h-4 w-4" aria-hidden="true" />
              {t("clear")}
            </Button>
            <Button
              type="button"
              size="sm"
              onClick={handleApply}
              disabled={!canApply}
              aria-label={t("applyAria")}
              data-testid="ai-refinement-apply"
            >
              {isApplying ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                  {t("applying")}
                </>
              ) : (
                <>
                  <Send className="h-4 w-4" aria-hidden="true" />
                  {t("apply")}
                </>
              )}
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
