"use client";

import { useEffect, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { CheckCircle2, Loader2, XCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { toast } from "@/components/ui/sonner";
import { useAuthStore } from "@/store/auth-store";
import { verifyEmailChange } from "@/lib/api/client";

/**
 * Email-change verification page (#777).
 *
 * The user lands here from the confirmation link sent to their NEW address.
 * The page POSTs the token to `/users/me/email-change/verify`; on success the
 * backend commits the new email. It then runs a silent refresh so the JWT (and
 * the in-memory session) carry the updated address, and optimistically updates
 * the store immediately.
 */
export default function EmailChangeVerifyPage() {
  const t = useTranslations("accountSettings.account.verify");
  const params = useParams<{ token: string }>();
  const router = useRouter();
  const setUserEmail = useAuthStore((s) => s.setUserEmail);
  const silentRefresh = useAuthStore((s) => s.silentRefresh);
  const [status, setStatus] = useState<"verifying" | "success" | "error">(
    "verifying",
  );
  // Prevent double-fire in React Strict Mode: the token is single-use.
  const started = useRef(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;

    const run = async () => {
      try {
        const newEmail = await verifyEmailChange(params.token);
        if (newEmail) {
          setUserEmail(newEmail);
          // Re-issue the JWT so the session carries the new identity.
          await silentRefresh();
          setStatus("success");
          toast.success(t("success"));
          return;
        }
        setStatus("error");
      } catch {
        setStatus("error");
      }
    };

    void run();
  }, [params.token, setUserEmail, silentRefresh, t]);

  if (status === "verifying") {
    return (
      <div
        className="flex min-h-[60vh] flex-col items-center justify-center gap-4"
        role="status"
        aria-live="polite"
        data-testid="email-change-verifying"
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

  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center px-4">
      <section
        className="flex w-full max-w-sm flex-col items-center gap-4 rounded-2xl border bg-card p-8 text-center"
        data-testid={
          status === "success" ? "email-change-success" : "email-change-failed"
        }
      >
        {status === "success" ? (
          <CheckCircle2 className="size-10 text-green-600" aria-hidden />
        ) : (
          <XCircle className="size-10 text-destructive" aria-hidden />
        )}
        <p className="text-sm font-medium">
          {status === "success" ? t("success") : t("failed")}
        </p>
        <Button
          className="cursor-pointer"
          onClick={() => router.replace("/account/settings")}
          data-testid="email-change-back-button"
        >
          {t("backToAccount")}
        </Button>
      </section>
    </div>
  );
}
