"use client";

import { useTranslations } from "next-intl";
import { AccountRail } from "@/components/account/account-rail";
import { AccountSection } from "@/components/account/account-section";
import { PreferencesSection } from "@/components/account/preferences-section";
import { AiProviderSection } from "@/components/account/ai-provider-section";
import { DataSection } from "@/components/account/data-section";
import { DangerZoneSection } from "@/components/account/danger-zone-section";
import { isAiFeatureEnabled } from "@/lib/constants";

/**
 * Account settings page (#383).
 *
 * Reachable from the top-bar profile button. Protected by the global
 * {@link AuthGuard}: `/account/settings` is not a public path, so an
 * unauthenticated visitor is redirected to `/login`.
 *
 * Chrome (audit #7): the global {@link TopBar} (help suppressed — no modal
 * here), a left identity/nav rail ({@link AccountRail}, which also hosts the
 * red logout), the content cards, and the shared {@link LandingFooter}. The
 * rail stacks above the content under the `md` breakpoint.
 *
 * Content sections: account (email + magic-link change), preferences (language
 * + theme), data export (GDPR) and danger zone (account deletion). Logout lives
 * in the rail.
 */
export default function AccountSettingsPage() {
  const t = useTranslations("accountSettings");

  return (
    <main
      className="flex-1 w-full max-w-[1100px] mx-auto px-4 md:px-6 py-8 md:py-12"
      data-testid="account-settings-page"
    >
      <div className="mb-8">
        <h1 className="font-serif text-2xl font-semibold tracking-tight">
          {t("title")}
        </h1>
        <p className="text-muted-foreground text-sm mt-1">{t("subtitle")}</p>
      </div>

      <div className="grid gap-8 md:grid-cols-[240px_1fr]">
        <AccountRail />
        <div className="flex flex-col gap-6 min-w-0">
          <AccountSection />
          <PreferencesSection />
          {isAiFeatureEnabled() && <AiProviderSection />}
          <DataSection />
          <DangerZoneSection />
        </div>
      </div>
    </main>
  );
}
