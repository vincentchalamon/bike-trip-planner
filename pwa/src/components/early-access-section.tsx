"use client";

import { useTranslations } from "next-intl";
import { useAuthStore } from "@/store/auth-store";
import { CtaButton } from "@/components/cta-button";

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

        {/*
         * Waiting list — displayed only for unauthenticated visitors.
         * No collection form yet: the backend endpoint is not implemented
         * (see discussion on PR #338). A "coming soon" notice prevents the
         * UI from silently discarding real email addresses.
         */}
        {!isAuthenticated && (
          <div
            className="border border-background/20 rounded-2xl p-6 bg-background/5 backdrop-blur-sm"
            data-testid="waiting-list-notice"
          >
            <p className="text-sm font-semibold opacity-80 mb-2">
              {t("waitingListTitle")}
            </p>
            <p className="text-sm opacity-70">{t("waitingListComingSoon")}</p>
          </div>
        )}
      </div>
    </section>
  );
}
