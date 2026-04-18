"use client";

import { Suspense } from "react";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useAuthStore } from "@/store/auth-store";
import { CtaButton } from "@/components/cta-button";
import { EarlyAccessForm } from "@/components/early-access-form";

function EarlyAccessConfirmation() {
  const t = useTranslations("earlyAccess");
  const searchParams = useSearchParams();
  if (searchParams.get("access") !== "confirmed") {
    return null;
  }

  return (
    <div
      className="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950/30 dark:text-green-200"
      role="status"
      data-testid="access-confirmed-message"
    >
      {t("accessConfirmed")}
    </div>
  );
}

export function EarlyAccessSection() {
  const t = useTranslations("landing.earlyAccess");
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  return (
    <section
      className="py-24 md:py-32 bg-foreground text-background"
      data-testid="section-early-access"
    >
      <div className="max-w-2xl mx-auto px-4 md:px-6 text-center">
        <h2 className="text-3xl md:text-4xl font-bold mb-4">{t("title")}</h2>
        <p className="text-lg opacity-75 mb-10">{t("description")}</p>

        <CtaButton label={t("ctaPrimary")} size="lg" className="mb-10" />

        {!isAuthenticated && (
          <div
            className="border border-background/20 rounded-2xl p-6 bg-background/5 backdrop-blur-sm"
            data-testid="waiting-list-notice"
          >
            <p className="text-sm font-semibold opacity-80 mb-4">
              {t("waitingListTitle")}
            </p>
            <Suspense fallback={null}>
              <EarlyAccessConfirmation />
            </Suspense>
            <div className="flex justify-center">
              <EarlyAccessForm />
            </div>
          </div>
        )}
      </div>
    </section>
  );
}
