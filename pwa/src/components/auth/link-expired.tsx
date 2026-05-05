"use client";

import { useTranslations } from "next-intl";
import { AlertTriangle } from "lucide-react";
import { Button } from "@/components/ui/button";

export type LinkExpiredProps = {
  /** Triggered when the user clicks the "Request a new link" button. */
  onRequestNew: () => void;
  /** Optional override for the error description (otherwise uses default i18n). */
  description?: string;
};

/**
 * Magic-link expired or invalid state.
 *
 * Displayed when verification of a magic-link token fails (expired, already
 * consumed, or malformed). Lets the user request a new link without
 * navigating back to the login page.
 */
export function LinkExpired({ onRequestNew, description }: LinkExpiredProps) {
  const t = useTranslations("auth");

  return (
    <div
      className="flex flex-col items-center text-center"
      style={{ gap: "var(--spacing-lg)" }}
      role="alert"
      data-testid="magic-link-expired"
    >
      <div
        className="flex items-center justify-center rounded-full"
        style={{
          width: "var(--spacing-4xl)",
          height: "var(--spacing-4xl)",
          backgroundColor:
            "color-mix(in oklab, var(--destructive) 12%, transparent)",
          color: "var(--destructive)",
        }}
        aria-hidden
      >
        <AlertTriangle className="size-8" />
      </div>

      <div className="space-y-2">
        <h2
          className="font-serif text-xl font-semibold tracking-tight"
          style={{ color: "var(--ink)" }}
          data-testid="magic-link-expired-title"
        >
          {t("linkExpiredTitle")}
        </h2>
        <p className="text-muted-foreground text-sm">
          {description ?? t("linkExpiredDescription")}
        </p>
      </div>

      <Button
        type="button"
        onClick={onRequestNew}
        data-testid="magic-link-request-new"
      >
        {t("requestNewLink")}
      </Button>
    </div>
  );
}
