"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { useCookieConsent } from "@/hooks/use-cookie-consent";
import { CookieModal } from "@/components/cookie-modal";

/**
 * GDPR consent banner pinned to the bottom of the screen. Shown on first visit
 * (no recorded decision). Accepting/refusing/saving persists the choice to the
 * shared `cookie-consent` localStorage key, which gates Plausible loading.
 */
export function CookieBanner() {
  const t = useTranslations("cookies");
  const { consent, shouldShowBanner, acceptAll, rejectAll, save } =
    useCookieConsent();
  const [modalOpen, setModalOpen] = useState(false);

  const handleSave = (analytics: boolean) => {
    save(analytics);
    setModalOpen(false);
  };

  const handleAcceptAll = () => {
    acceptAll();
    setModalOpen(false);
  };

  const handleRejectAll = () => {
    rejectAll();
    setModalOpen(false);
  };

  // The modal can still be reopened from /privacy in the future, but the banner
  // itself only renders until a decision is recorded.
  if (!shouldShowBanner) {
    return modalOpen ? (
      <CookieModal
        open={modalOpen}
        onOpenChange={setModalOpen}
        initialAnalytics={consent?.analytics ?? false}
        onSave={handleSave}
        onAcceptAll={handleAcceptAll}
        onRejectAll={handleRejectAll}
      />
    ) : null;
  }

  return (
    <>
      <div
        role="dialog"
        aria-label={t("banner.ariaLabel")}
        data-testid="cookie-banner"
        className="bg-background fixed inset-x-0 bottom-0 z-50 border-t p-4 shadow-lg sm:p-6"
      >
        <div className="mx-auto flex max-w-4xl flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-muted-foreground text-sm">{t("banner.message")}</p>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setModalOpen(true)}
              data-testid="cookie-customize"
            >
              {t("banner.customize")}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={handleRejectAll}
              data-testid="cookie-reject-all"
            >
              {t("rejectAll")}
            </Button>
            <Button
              size="sm"
              onClick={handleAcceptAll}
              data-testid="cookie-accept-all"
            >
              {t("acceptAll")}
            </Button>
          </div>
        </div>
      </div>

      <CookieModal
        open={modalOpen}
        onOpenChange={setModalOpen}
        initialAnalytics={consent?.analytics ?? false}
        onSave={handleSave}
        onAcceptAll={handleAcceptAll}
        onRejectAll={handleRejectAll}
      />
    </>
  );
}
