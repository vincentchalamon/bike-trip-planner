"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { Bike } from "lucide-react";
import { AccountSection } from "@/components/account/account-section";
import { PreferencesSection } from "@/components/account/preferences-section";
import { DataSection } from "@/components/account/data-section";
import { DangerZoneSection } from "@/components/account/danger-zone-section";
import { LogoutSection } from "@/components/account/logout-section";

/**
 * Account settings page (#383).
 *
 * Reachable from the top-bar profile button. Protected by the global
 * {@link AuthGuard}: `/account/settings` is not a public path, so an
 * unauthenticated visitor is redirected to `/login`.
 *
 * Sections: account (email + magic-link change), preferences (language +
 * theme), data export (GDPR), danger zone (account deletion) and logout.
 */
export default function AccountSettingsPage() {
  const t = useTranslations("accountSettings");

  return (
    <div className="min-h-screen bg-background">
      <header className="sticky top-0 z-30 w-full border-b border-border bg-background/80 backdrop-blur supports-[backdrop-filter]:bg-background/60">
        <div className="max-w-[800px] mx-auto flex items-center gap-2 px-4 md:px-6 h-14">
          <Link
            href="/"
            className="flex items-center gap-2 font-bold text-base text-brand hover:opacity-80 transition-opacity"
          >
            <Bike className="h-5 w-5" aria-hidden="true" />
            <span className="hidden sm:inline">Bike Trip Planner</span>
          </Link>
        </div>
      </header>

      <main
        className="max-w-[800px] mx-auto px-4 md:px-6 py-8 md:py-12"
        data-testid="account-settings-page"
      >
        <div className="mb-8">
          <h1 className="font-serif text-2xl font-semibold tracking-tight">
            {t("title")}
          </h1>
          <p className="text-muted-foreground text-sm mt-1">{t("subtitle")}</p>
        </div>

        <div className="flex flex-col gap-6">
          <AccountSection />
          <PreferencesSection />
          <DataSection />
          <DangerZoneSection />
          <LogoutSection />
        </div>
      </main>
    </div>
  );
}
