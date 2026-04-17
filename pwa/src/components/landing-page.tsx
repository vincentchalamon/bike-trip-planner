"use client";

import { useSearchParams } from "next/navigation";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Bike } from "lucide-react";
import { Button } from "@/components/ui/button";
import { EarlyAccessForm } from "@/components/early-access-form";

/**
 * Public landing page for unauthenticated users.
 *
 * Shown when the user visits / without being authenticated.
 * Contains a CTA to sign in, a brief feature overview, and
 * the early access sign-up form (section 8).
 *
 * Also handles the ?access=confirmed query param (set by the backend
 * after email verification) to show a confirmation message.
 */
export function LandingPage() {
  const t = useTranslations("earlyAccess");
  const searchParams = useSearchParams();
  const accessConfirmed = searchParams.get("access") === "confirmed";

  return (
    <main className="min-h-screen flex flex-col">
      {/* Hero section */}
      <section className="flex flex-col items-center justify-center flex-1 px-4 py-16 md:py-24 text-center">
        <div className="flex items-center gap-3 mb-6">
          <Bike className="h-8 w-8 text-primary" />
          <h1 className="text-3xl md:text-4xl font-bold tracking-tight">
            Bike Trip Planner
          </h1>
        </div>
        <p className="text-lg md:text-xl text-muted-foreground max-w-lg mb-10">
          {t("sectionDescription")}
        </p>

        {/* CTA button */}
        <Button
          asChild
          size="lg"
          className="text-base"
          data-testid="cta-create-trip"
        >
          <Link href="/login">{t("ctaCreateTrip")}</Link>
        </Button>
      </section>

      {/* Early access section (section 8) */}
      <section
        id="early-access"
        className="bg-muted/40 border-t border-border px-4 py-12 md:py-16"
        aria-labelledby="early-access-heading"
      >
        <div className="max-w-xl mx-auto flex flex-col items-center gap-6 text-center">
          <div className="space-y-2">
            <h2
              id="early-access-heading"
              className="text-2xl font-bold tracking-tight"
            >
              {t("sectionTitle")}
            </h2>
            <p className="text-muted-foreground text-sm max-w-md">
              {t("earlyAccessDescription")}
            </p>
          </div>

          {/* Access confirmed feedback */}
          {accessConfirmed && (
            <div
              className="bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-800 rounded-lg px-4 py-3 text-sm text-green-800 dark:text-green-200"
              role="status"
              data-testid="access-confirmed-message"
            >
              {t("accessConfirmed")}
            </div>
          )}

          <EarlyAccessForm />
        </div>
      </section>
    </main>
  );
}
