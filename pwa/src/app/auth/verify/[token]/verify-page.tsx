"use client";

import { useEffect, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { useAuthStore, parseJwtPayload } from "@/store/auth-store";
import { API_URL } from "@/lib/constants";
import { LinkExpired } from "@/components/auth/link-expired";

/**
 * Magic link verification page.
 *
 * When the user clicks the magic link in their email, they land here.
 * This page POSTs the token to the backend `/auth/verify` endpoint.
 * On success the backend returns a JWT access token and sets a
 * refresh_token httpOnly cookie; the frontend stores the JWT and
 * redirects to the home page.
 *
 * On failure (invalid / expired token) the page renders the `LinkExpired`
 * component, which lets the user request a fresh magic link without leaving
 * the verification flow.
 */
export default function VerifyPage() {
  const t = useTranslations("auth");
  const params = useParams<{ token: string }>();
  const router = useRouter();
  const setAuth = useAuthStore((s) => s.setAuth);
  const [error, setError] = useState<string | null>(null);
  const [verifying, setVerifying] = useState(true);
  // Prevent double-fire in React Strict Mode (dev): the token is single-use,
  // so a second POST would fail with "already consumed".
  const verifyStarted = useRef(false);

  useEffect(() => {
    // Guard against React Strict Mode double-fire: the token is single-use,
    // so a second POST would fail with "already consumed".
    if (verifyStarted.current) return;
    verifyStarted.current = true;

    const verify = async () => {
      try {
        const res = await fetch(`${API_URL}/auth/verify`, {
          method: "POST",
          headers: { "Content-Type": "application/ld+json" },
          body: JSON.stringify({ token: params.token }),
          credentials: "include",
        });

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
    };

    void verify();
    // No cleanup — the useRef guard prevents double-fire, and we must not
    // cancel the in-flight verify (the token is consumed server-side).
  }, [params.token, setAuth, router, t]);

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
