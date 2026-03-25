"use client";

import { useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useAuthStore } from "@/store/auth-store";
import { API_URL } from "@/lib/constants";

/**
 * Magic link verification page.
 *
 * When the user clicks the magic link in their email, they land here.
 * This page calls the backend `/auth/verify/{token}` endpoint to validate
 * the token. On success, it attempts a silent refresh to obtain a JWT
 * (the backend sets a refresh_token httpOnly cookie in its response),
 * then redirects to the home page.
 */
export default function VerifyPage() {
  const t = useTranslations("auth");
  const params = useParams<{ token: string }>();
  const router = useRouter();
  const { setAuth, silentRefresh } = useAuthStore();
  const [error, setError] = useState<string | null>(null);
  const [verifying, setVerifying] = useState(true);

  useEffect(() => {
    const verify = async () => {
      try {
        // Call the backend verify endpoint. For web browsers, the backend
        // returns a 302 redirect with the refresh_token cookie set.
        // We use redirect: 'manual' to prevent the browser from following
        // the redirect, allowing us to capture the cookie being set.
        const res = await fetch(
          `${API_URL}/auth/verify/${encodeURIComponent(params.token)}`,
          {
            credentials: "include",
            redirect: "manual",
          },
        );

        // The backend may return:
        // - 302 redirect (web flow) — refresh_token cookie is set
        // - 200 JSON (Capacitor flow) — { token, refresh_token }
        // - 401 — invalid/expired token

        if (res.type === "opaqueredirect" || res.status === 302) {
          // Cookie was set by the redirect response. Use silentRefresh
          // to exchange it for a JWT.
          const refreshed = await silentRefresh();
          if (refreshed) {
            router.replace("/");
            return;
          }
          setError(t("verifyFailed"));
          setVerifying(false);
          return;
        }

        if (res.ok) {
          // JSON response (Capacitor flow or direct JSON)
          const data = (await res.json()) as {
            token: string;
            refresh_token?: string;
          };

          if (data.token) {
            // Parse user info from the JWT
            const parts = data.token.split(".");
            if (parts.length === 3) {
              try {
                const payload = JSON.parse(atob(parts[1])) as {
                  sub: string;
                  email: string;
                };
                setAuth(data.token, {
                  id: payload.sub,
                  email: payload.email,
                });
                router.replace("/");
                return;
              } catch {
                // Fall through to error
              }
            }
          }
        }

        setError(t("verifyFailed"));
      } catch {
        setError(t("verifyFailed"));
      } finally {
        setVerifying(false);
      }
    };

    verify();
  }, [params.token, router, setAuth, silentRefresh, t]);

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
