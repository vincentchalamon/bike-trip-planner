"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { ArrowLeft, Bike } from "lucide-react";
import { useAuthStore } from "@/store/auth-store";
import { AttributionFooter } from "@/components/attribution-footer";
import { MagicLinkForm } from "@/components/auth/magic-link-form";

export default function LoginPage() {
  const t = useTranslations("auth");
  const tEarlyAccess = useTranslations("earlyAccess");
  const tFooter = useTranslations("footer");
  const router = useRouter();
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  useEffect(() => {
    if (isAuthenticated) {
      router.replace("/");
    }
  }, [isAuthenticated, router]);

  if (isAuthenticated) {
    return null;
  }

  return (
    <div
      className="relative flex min-h-screen flex-col items-center justify-center"
      style={{
        backgroundColor: "var(--surface)",
        padding: "var(--spacing-lg)",
      }}
    >
      <Link
        href="/"
        className="absolute left-4 top-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground sm:left-6 sm:top-6"
        data-testid="login-back-link"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t("backToHome")}
      </Link>

      <main
        className="w-full max-w-sm"
        style={{ display: "grid", gap: "var(--spacing-2xl)" }}
      >
        <div
          className="flex flex-col items-center text-center"
          style={{ gap: "var(--spacing-md)" }}
        >
          <Link
            href="/"
            aria-label="Bike Trip Planner"
            className="flex items-center justify-center rounded-full transition-colors"
            style={{
              width: "var(--spacing-3xl)",
              height: "var(--spacing-3xl)",
              backgroundColor: "var(--accent-soft)",
              color: "var(--accent-ink)",
            }}
          >
            <Bike className="size-6" aria-hidden />
          </Link>
          <div className="space-y-2">
            <h1
              className="font-serif text-2xl font-semibold tracking-tight"
              style={{ color: "var(--ink)" }}
            >
              {t("loginTitle")}
            </h1>
            <p className="text-muted-foreground text-sm">
              {t("loginDescription")}
            </p>
          </div>
        </div>

        <section
          className="rounded-2xl border bg-card"
          style={{
            padding: "var(--spacing-xl)",
            boxShadow: "var(--shadow-soft)",
            borderColor: "var(--border)",
          }}
          data-testid="login-card"
        >
          <MagicLinkForm />
        </section>

        <aside
          className="rounded-xl border text-center"
          style={{
            padding: "var(--spacing-base)",
            backgroundColor: "var(--accent-soft)",
            borderColor:
              "color-mix(in oklab, var(--accent-brand) 20%, transparent)",
            display: "grid",
            gap: "var(--spacing-xs)",
          }}
          data-testid="early-access-banner"
        >
          <p
            className="text-sm font-medium"
            style={{ color: "var(--accent-ink)" }}
          >
            {tEarlyAccess("loginBoxTitle")}
          </p>
          <p className="text-muted-foreground text-xs">
            {tEarlyAccess("loginBoxDescription")}
          </p>
          <Link
            href="/#early-access"
            className="text-xs font-medium underline underline-offset-4 hover:no-underline"
            style={{ color: "var(--accent-ink)" }}
            data-testid="early-access-link"
          >
            {tEarlyAccess("loginBoxCta")}
          </Link>
        </aside>
      </main>

      <footer
        className="mt-8 flex flex-col items-center text-center"
        style={{ gap: "var(--spacing-sm)" }}
      >
        <Link
          href="/faq"
          className="text-muted-foreground hover:text-foreground text-xs transition-colors"
          data-testid="footer-faq-link"
        >
          {tFooter("faq")}
        </Link>
        <AttributionFooter />
      </footer>
    </div>
  );
}
