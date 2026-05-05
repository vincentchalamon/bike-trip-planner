"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { MailCheck } from "lucide-react";
import { Button } from "@/components/ui/button";

export type EmailSentProps = {
  email: string;
  /** Cooldown duration in seconds before the user can request a new link. */
  cooldownSeconds?: number;
  /** Triggered when the user clicks the resend button (only enabled after cooldown). */
  onResend: () => void;
};

/**
 * Email-sent state of the magic-link flow.
 *
 * Shows a confirmation message after a magic link has been requested, with a
 * countdown timer (default 60s) before the "Resend link" button becomes
 * active. Implements both visual sub-states `sent` (timer running) and
 * `sent-ready` (timer terminated) within a single component.
 */
export function EmailSent({
  email,
  cooldownSeconds = 60,
  onResend,
}: EmailSentProps) {
  const t = useTranslations("auth");
  const [remaining, setRemaining] = useState(cooldownSeconds);

  useEffect(() => {
    if (remaining <= 0) return;
    const id = window.setTimeout(() => {
      setRemaining((value) => Math.max(0, value - 1));
    }, 1000);
    return () => window.clearTimeout(id);
  }, [remaining]);

  const ready = remaining <= 0;
  const subState = ready ? "sent-ready" : "sent";

  const handleResend = () => {
    if (!ready) return;
    setRemaining(cooldownSeconds);
    onResend();
  };

  return (
    <div
      className="flex flex-col items-center text-center"
      style={{ gap: "var(--spacing-lg)" }}
      role="status"
      data-testid="magic-link-sent"
      data-substate={subState}
    >
      <div
        className="flex items-center justify-center rounded-full"
        style={{
          width: "var(--spacing-4xl)",
          height: "var(--spacing-4xl)",
          backgroundColor: "var(--accent-soft)",
          color: "var(--accent-ink)",
        }}
        aria-hidden
      >
        <MailCheck className="size-8" />
      </div>

      <div className="space-y-2">
        <h2
          className="font-serif text-xl font-semibold tracking-tight"
          style={{ color: "var(--ink)" }}
          data-testid="magic-link-sent-title"
        >
          {t("checkYourInbox")}
        </h2>
        <p className="text-muted-foreground text-sm">
          {t.rich("linkSentTo", {
            email,
            strong: (chunks) => (
              <strong className="text-foreground font-medium">{chunks}</strong>
            ),
          })}
        </p>
        <p className="text-muted-foreground text-xs">{t("checkSpamHint")}</p>
      </div>

      <Button
        type="button"
        variant="outline"
        onClick={handleResend}
        disabled={!ready}
        data-testid="magic-link-resend"
        aria-live="polite"
      >
        {ready
          ? t("resendLink")
          : t("resendLinkCountdown", { seconds: remaining })}
      </Button>
    </div>
  );
}
