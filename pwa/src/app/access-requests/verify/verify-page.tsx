"use client";

import { useEffect, useRef } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { API_URL } from "@/lib/constants";

/**
 * Access request email verification page.
 *
 * When the user clicks the verification link in their email, they land here
 * at /access-requests/verify?email=...&expires=...&signature=...
 *
 * This page `fetch`es the backend GET /access-requests/verify endpoint (which
 * validates the HMAC and marks the access request as verified), then navigates
 * client-side to /?access=confirmed. It uses `fetch` rather than a full-page
 * navigation on purpose: the backend route shares this URL, and Caddy routes any
 * `text/html` navigation back to the PWA — a browser redirect to the same path
 * would loop forever. A `fetch` does not send an html Accept header, so it reaches
 * the PHP controller instead.
 *
 * The landing page then reads the ?access=confirmed param and shows a
 * confirmation message.
 */
export default function VerifyPage() {
  const t = useTranslations("earlyAccess");
  const searchParams = useSearchParams();
  const router = useRouter();
  const verifyStarted = useRef(false);

  useEffect(() => {
    if (verifyStarted.current) return;
    verifyStarted.current = true;

    const email = searchParams.get("email");
    const expires = searchParams.get("expires");
    const signature = searchParams.get("signature");

    if (!email || !expires || !signature) {
      router.replace("/");
      return;
    }

    const params = new URLSearchParams({ email, expires, signature });
    const verify = async () => {
      try {
        // Reach the PHP controller with a `fetch` (Accept: */*): it does NOT
        // match Caddy's `@pwa` (text/html) route, so it hits the backend.
        // A full-page navigation to this same-origin path would instead be
        // routed back to this page by Caddy — an infinite loop.
        await fetch(`${API_URL}/access-requests/verify?${params.toString()}`, {
          credentials: "include",
        });
      } catch {
        // Anti-enumeration: land on the same confirmation regardless of outcome.
      } finally {
        router.replace("/?access=confirmed");
      }
    };

    void verify();
  }, [searchParams, router]);

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
      data-testid="access-request-verifying"
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
