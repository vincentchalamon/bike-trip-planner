"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { API_URL } from "@/lib/constants";

/**
 * Early access email signup form.
 *
 * POSTs to POST /access-requests. Always shows a neutral confirmation
 * (anti-enumeration). On 429 (rate limit), shows a throttle message.
 */
export function EarlyAccessForm() {
  const t = useTranslations("earlyAccess");
  const [email, setEmail] = useState("");
  const [loading, setLoading] = useState(false);
  const [status, setStatus] = useState<"idle" | "success" | "throttled">(
    "idle",
  );
  const [emailError, setEmailError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const trimmed = email.trim();
    if (!trimmed) return;

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
      setEmailError(t("invalidEmail"));
      return;
    }

    setEmailError(null);
    setLoading(true);

    try {
      const res = await fetch(`${API_URL}/access-requests`, {
        method: "POST",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({ email: trimmed }),
      });

      if (res.status === 429) {
        setStatus("throttled");
      } else {
        setStatus("success");
      }
    } catch {
      // Network error — still show neutral confirmation
      setStatus("success");
    } finally {
      setLoading(false);
    }
  };

  if (status === "success") {
    return (
      <div
        className="bg-muted rounded-lg p-4 text-center text-sm"
        role="status"
        data-testid="early-access-success"
      >
        {t("successMessage")}
      </div>
    );
  }

  if (status === "throttled") {
    return (
      <div
        className="bg-destructive/10 border border-destructive/30 rounded-lg p-4 text-center text-sm text-destructive"
        role="alert"
        data-testid="early-access-throttled"
      >
        {t("throttledMessage")}
      </div>
    );
  }

  return (
    <form
      onSubmit={(e) => void handleSubmit(e)}
      className="flex flex-col sm:flex-row gap-3 w-full max-w-md"
      data-testid="early-access-form"
    >
      <div className="flex-1 space-y-1">
        <Input
          id="early-access-email"
          type="email"
          placeholder={t("emailPlaceholder")}
          value={email}
          onChange={(e) => {
            setEmail(e.target.value);
            setEmailError(null);
          }}
          required
          autoComplete="email"
          disabled={loading}
          aria-label={t("emailLabel")}
          aria-describedby={emailError ? "early-access-error" : undefined}
          aria-invalid={!!emailError}
          data-testid="early-access-email-input"
          className={
            emailError
              ? "ring-2 ring-destructive"
              : ""
          }
        />
        {emailError && (
          <p
            id="early-access-error"
            className="text-xs text-destructive"
          >
            {emailError}
          </p>
        )}
      </div>
      <Button
        type="submit"
        disabled={loading}
        data-testid="early-access-submit"
      >
        {loading ? t("sending") : t("submitButton")}
      </Button>
    </form>
  );
}
