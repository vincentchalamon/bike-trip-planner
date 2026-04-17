"use client";

import { useEffect, useRef } from "react";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { API_URL } from "@/lib/constants";

/**
 * Access request email verification page.
 *
 * When the user clicks the verification link in their email, they land here
 * at /access-requests/verify?email=...&expires=...&signature=...
 *
 * This page forwards the query params to the backend GET /access-requests/verify
 * endpoint, which validates the HMAC, marks the access request as verified, and
 * redirects to {FRONTEND_URL}?access=confirmed.
 *
 * The landing page then reads the ?access=confirmed param and shows a
 * confirmation message.
 */
export default function VerifyPage() {
  const t = useTranslations("earlyAccess");
  const searchParams = useSearchParams();
  const redirectStarted = useRef(false);

  useEffect(() => {
    if (redirectStarted.current) return;
    redirectStarted.current = true;

    const email = searchParams.get("email");
    const expires = searchParams.get("expires");
    const signature = searchParams.get("signature");

    if (!email || !expires || !signature) {
      // Missing params — redirect to home, backend will show generic confirmation
      window.location.replace(`${API_URL}/access-requests/verify`);
      return;
    }

    const params = new URLSearchParams({ email, expires, signature });
    window.location.replace(
      `${API_URL}/access-requests/verify?${params.toString()}`,
    );
  }, [searchParams]);

  return (
    <div className="flex min-h-screen items-center justify-center p-4">
      <div className="text-muted-foreground text-sm">{t("verifying")}</div>
    </div>
  );
}
