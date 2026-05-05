"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Mail } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useAuthStore } from "@/store/auth-store";
import { EmailSent } from "./email-sent";
import { LinkExpired } from "./link-expired";

/**
 * Public magic-link entry states.
 *
 * Restricted to states that make sense as initial render targets:
 * - `form`    : initial email form
 * - `expired` : verification failed (link invalid or expired)
 *
 * The `sent` / `sent-ready` sub-states are internal-only (they require a
 * `submittedEmail`, which only exists after a successful form submission)
 * and therefore must not be reachable via the public `initialState` prop.
 */
export type MagicLinkState = "form" | "expired";

/**
 * Internal state machine — superset of {@link MagicLinkState} that adds the
 * `sent` transition reached after a successful submit. `sent-ready` is purely
 * visual and surfaced via the `data-substate` attribute on the `<EmailSent>`
 * root element.
 */
type InternalMagicLinkState = MagicLinkState | "sent";

export type MagicLinkFormProps = {
  /** Initial state — defaults to `form`. */
  initialState?: MagicLinkState;
  /** Optional override for the expired description (e.g. localized backend error). */
  expiredDescription?: string;
  /** Cooldown in seconds before the resend button becomes active again. */
  cooldownSeconds?: number;
};

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Magic-link authentication form with explicit visual state machine.
 *
 * Drives the four sprint-27 states (`form`, `sent`, `sent-ready`, `expired`)
 * around the stateless `requestMagicLink` action exposed by the auth store.
 * Validation is inline (no toasts) and the success branch always shows a
 * neutral confirmation regardless of whether the address is registered
 * (anti-enumeration).
 */
export function MagicLinkForm({
  initialState = "form",
  expiredDescription,
  cooldownSeconds = 60,
}: MagicLinkFormProps) {
  const t = useTranslations("auth");
  const requestMagicLink = useAuthStore((s) => s.requestMagicLink);

  const [state, setState] = useState<InternalMagicLinkState>(initialState);
  const [email, setEmail] = useState("");
  const [submittedEmail, setSubmittedEmail] = useState("");
  const [emailError, setEmailError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const submit = async (target: string) => {
    setLoading(true);
    try {
      await requestMagicLink(target);
    } catch {
      // Silently ignore — anti-enumeration: the user always sees `sent`
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const trimmed = email.trim();

    if (!trimmed) {
      setEmailError(t("emailRequired"));
      return;
    }
    if (!EMAIL_RE.test(trimmed)) {
      setEmailError(t("emailInvalid"));
      return;
    }

    setEmailError(null);
    await submit(trimmed);
    setSubmittedEmail(trimmed);
    setState("sent");
  };

  const handleResend = () => {
    if (!submittedEmail) return;
    void submit(submittedEmail);
  };

  const handleRequestNew = () => {
    setState("form");
    setEmail(submittedEmail);
    setEmailError(null);
  };

  if (state === "sent") {
    return (
      <EmailSent
        email={submittedEmail}
        cooldownSeconds={cooldownSeconds}
        onResend={handleResend}
      />
    );
  }

  if (state === "expired") {
    return (
      <LinkExpired
        description={expiredDescription}
        onRequestNew={handleRequestNew}
      />
    );
  }

  return (
    <form
      onSubmit={(e) => void handleSubmit(e)}
      noValidate
      className="space-y-4"
      data-testid="magic-link-form"
      data-state={state}
    >
      <div className="space-y-2">
        <label
          htmlFor="magic-link-email"
          className="text-sm font-medium"
          style={{ color: "var(--ink)" }}
        >
          {t("emailLabel")}
        </label>
        <div className="relative">
          <Mail
            className="text-muted-foreground pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2"
            aria-hidden
          />
          <Input
            id="magic-link-email"
            data-testid="magic-link-input"
            type="email"
            inputMode="email"
            placeholder={t("emailPlaceholder")}
            value={email}
            onChange={(e) => {
              setEmail(e.target.value);
              if (emailError) setEmailError(null);
            }}
            required
            autoComplete="email"
            autoFocus
            disabled={loading}
            aria-invalid={!!emailError}
            aria-describedby={emailError ? "magic-link-email-error" : undefined}
            className="pl-9"
          />
        </div>
        {emailError && (
          <p
            id="magic-link-email-error"
            className="text-destructive text-xs"
            data-testid="magic-link-email-error"
            role="alert"
          >
            {emailError}
          </p>
        )}
      </div>

      <Button
        type="submit"
        className="w-full"
        disabled={loading}
        data-testid="magic-link-submit"
      >
        {loading ? t("sending") : t("sendLink")}
      </Button>
    </form>
  );
}
