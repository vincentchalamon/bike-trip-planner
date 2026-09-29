"use client";

import { useEffect, useRef } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { API_URL } from "@/lib/constants";
import { takeUrlFragment } from "@/lib/url-fragment";

/**
 * Access request email verification page.
 *
 * When the user clicks the verification link in their email, they land here
 * at /access-requests/verify#id=...&expires=...&signature=... The signed
 * payload is in the fragment, which a browser never sends, and it names the
 * request by id, never by address: nothing of the link reaches an access log.
 *
 * This page takes the payload out of the address bar and POSTs it to the
 * backend /access-requests/verify endpoint (which validates the HMAC and marks
 * the access request as verified), then navigates client-side to
 * /?access=confirmed. A `fetch` with a JSON body does not send an html Accept
 * header, so Caddy routes it to the PHP controller rather than back to this
 * page.
 *
 * The landing page then reads the ?access=confirmed param and shows a
 * confirmation message.
 */
export default function VerifyPage() {
  const t = useTranslations("earlyAccess");
  const router = useRouter();
  const verifyStarted = useRef(false);

  useEffect(() => {
    if (verifyStarted.current) return;
    verifyStarted.current = true;

    const params = new URLSearchParams(takeUrlFragment());
    const id = params.get("id");
    const expires = params.get("expires");
    const signature = params.get("signature");
    if (!id || !expires || !signature) {
      router.replace("/");
      return;
    }

    const verify = async () => {
      try {
        await fetch(`${API_URL}/access-requests/verify`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id, expires, signature }),
          credentials: "include",
        });
      } catch {
        // Anti-enumeration: land on the same confirmation regardless of outcome.
      } finally {
        router.replace("/?access=confirmed");
      }
    };

    void verify();
  }, [router]);

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
