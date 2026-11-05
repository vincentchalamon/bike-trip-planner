"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { useAuthStore, parseJwtPayload } from "@/store/auth-store";
import { LinkExpired } from "@/components/auth/link-expired";
import { Button } from "@/components/ui/button";
import { takeUrlFragment } from "@/lib/url-fragment";

/**
 * Magic link verification page.
 *
 * When the user clicks the magic link in their email, they land here, with the
 * token in the fragment (`/auth/verify#<token>`) so no server log ever sees it.
 * This page takes it out of the address bar and POSTs it to the BFF `/api/auth/verify` route handler
 * (ADR-053). On success the BFF stores the refresh_token in an httpOnly cookie
 * and returns only the JWT access token; the frontend stores the JWT and
 * redirects to the home page. The refresh token never reaches the browser JS.
 *
 * A link this browser did not request (no matching login nonce cookie) is not
 * consumed: the BFF answers 409 with the account email, and the page asks the
 * user before replaying with `confirm` (SEC-15). That is the legitimate path
 * for a link requested on another device, and the one an attacker forwarding
 * a link to their own account would need the victim to accept knowingly.
 *
 * On failure (invalid / expired token) the page renders the `LinkExpired`
 * component, which lets the user request a fresh magic link without leaving
 * the verification flow.
 */
export default function VerifyPage() {
  const t = useTranslations("auth");
  const router = useRouter();
  const setAuth = useAuthStore((s) => s.setAuth);
  const [error, setError] = useState<string | null>(null);
  const [verifying, setVerifying] = useState(true);
  // Account the link opens, when it was not requested from this browser (SEC-15).
  const [confirmEmail, setConfirmEmail] = useState<string | null>(null);
  // The fragment is erased on read, so the confirmation replays from here.
  const tokenRef = useRef("");
  // Prevent double-fire in React Strict Mode (dev): the token is single-use,
  // so a second POST would fail with "already consumed".
  const verifyStarted = useRef(false);

  const verify = useCallback(
    async (confirm: boolean) => {
      setVerifying(true);
      try {
        const res = await fetch(`/api/auth/verify`, {
          method: "POST",
          headers: { "Content-Type": "application/ld+json" },
          body: JSON.stringify({ token: tokenRef.current, confirm }),
          credentials: "include",
        });

        if (res.status === 409) {
          const data = (await res.json()) as { email?: string };
          if (data.email) {
            setConfirmEmail(data.email);
            return;
          }
        }

        if (res.ok) {
          const data = (await res.json()) as { token: string };
          const payload = parseJwtPayload(data.token);
          if (payload) {
            setAuth(data.token, { id: payload.sub, email: payload.email });
            router.replace("/");
            return;
          }
        }

        setError(t("verifyFailed"));
      } catch {
        setError(t("verifyFailed"));
      } finally {
        setVerifying(false);
      }
    },
    [setAuth, router, t],
  );

  useEffect(() => {
    if (verifyStarted.current) return;
    verifyStarted.current = true;

    tokenRef.current = takeUrlFragment();
    if (tokenRef.current === "") {
      setError(t("verifyFailed"));
      setVerifying(false);
      return;
    }
    void verify(false);
    // No cleanup — the useRef guard prevents double-fire, and we must not
    // cancel the in-flight verify (the token is consumed server-side).
  }, [verify, t]);

  if (verifying) {
    return (
      <div
        className="flex min-h-screen flex-col items-center justify-center"
        style={{
          backgroundColor: "var(--surface)",
          padding: "var(--spacing-lg)",
          gap: "var(--spacing-md)",
        }}
        role="status"
        aria-live="polite"
        data-testid="magic-link-verifying"
      >
        <Loader2
          className="size-8 animate-spin"
          style={{ color: "var(--accent-brand)" }}
          aria-hidden
        />
        <p className="text-muted-foreground text-sm">{t("verifying")}</p>
      </div>
    );
  }

  if (confirmEmail && !error) {
    return (
      <div
        className="flex min-h-screen flex-col items-center justify-center"
        style={{
          backgroundColor: "var(--surface)",
          padding: "var(--spacing-lg)",
        }}
      >
        <section
          className="w-full max-w-sm rounded-2xl border bg-card"
          style={{
            padding: "var(--spacing-xl)",
            boxShadow: "var(--shadow-soft)",
            borderColor: "var(--border)",
          }}
          data-testid="magic-link-confirm"
        >
          <div
            className="flex flex-col items-center text-center"
            style={{ gap: "var(--spacing-lg)" }}
          >
            <div className="space-y-2">
              <h2
                className="font-serif text-xl font-semibold tracking-tight"
                style={{ color: "var(--ink)" }}
              >
                {t("confirmSignInTitle")}
              </h2>
              <p className="text-sm" data-testid="magic-link-confirm-email">
                {t.rich("confirmSignInAccount", {
                  email: confirmEmail,
                  strong: (chunks) => <strong>{chunks}</strong>,
                })}
              </p>
              <p className="text-muted-foreground text-sm">
                {t("confirmSignInWarning")}
              </p>
            </div>
            <div
              className="flex w-full flex-col"
              style={{ gap: "var(--spacing-sm)" }}
            >
              <Button
                type="button"
                onClick={() => void verify(true)}
                data-testid="magic-link-confirm-continue"
              >
                {t("confirmSignInContinue")}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => router.replace("/")}
                data-testid="magic-link-confirm-cancel"
              >
                {t("confirmSignInCancel")}
              </Button>
            </div>
          </div>
        </section>
      </div>
    );
  }

  if (error) {
    return (
      <div
        className="flex min-h-screen flex-col items-center justify-center"
        style={{
          backgroundColor: "var(--surface)",
          padding: "var(--spacing-lg)",
        }}
      >
        <section
          className="w-full max-w-sm rounded-2xl border bg-card"
          style={{
            padding: "var(--spacing-xl)",
            boxShadow: "var(--shadow-soft)",
            borderColor: "var(--border)",
          }}
        >
          <LinkExpired
            description={error}
            onRequestNew={() => router.replace("/login")}
          />
        </section>
      </div>
    );
  }

  return null;
}
