"use client";

import { useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useAuthStore, parseJwtPayload } from "@/store/auth-store";
import { API_URL } from "@/lib/constants";

/**
 * Magic link verification page.
 *
 * When the user clicks the magic link in their email, they land here.
 * This page POSTs the token to the backend `/auth/verify` endpoint.
 * On success the backend returns a JWT access token and sets a
 * refresh_token httpOnly cookie; the frontend stores the JWT and
 * redirects to the home page.
 */
export default function VerifyPage() {
  const t = useTranslations("auth");
  const params = useParams<{ token: string }>();
  const router = useRouter();
  const setAuth = useAuthStore((s) => s.setAuth);
  const [error, setError] = useState<string | null>(null);
  const [verifying, setVerifying] = useState(true);

  useEffect(() => {
    const verify = async () => {
      try {
        const res = await fetch(`${API_URL}/auth/verify`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.token]);

  if (verifying) {
    return (
      <div className="flex min-h-screen items-center justify-center p-4">
        <div className="text-muted-foreground text-sm">{t("verifying")}</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex min-h-screen items-center justify-center p-4">
        <div className="w-full max-w-sm space-y-4 text-center">
          <p className="text-destructive text-sm">{error}</p>
          <a
            href="/login"
            className="text-primary text-sm underline underline-offset-4"
          >
            {t("backToLogin")}
          </a>
        </div>
      </div>
    );
  }

  return null;
}
