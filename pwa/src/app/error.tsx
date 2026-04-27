"use client";

import { useEffect } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";

interface ErrorBoundaryProps {
  error: Error & { digest?: string };
  reset: () => void;
}

export default function ErrorBoundary({ error, reset }: ErrorBoundaryProps) {
  const t = useTranslations("errorPages.error");

  useEffect(() => {
    // Log the original error for diagnostics; the user only sees a generic message.
    console.error(error);
  }, [error]);

  return (
    <main
      className="flex min-h-screen items-center justify-center px-4 py-12 bg-[var(--color-surface)] text-[var(--color-ink)]"
      data-testid="error-page"
    >
      <div className="text-center space-y-6 max-w-md">
        <div
          className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-[var(--color-accent-soft)] text-[var(--color-accent-ink)]"
          aria-hidden="true"
        >
          <svg
            viewBox="0 0 24 24"
            className="h-10 w-10"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
          </svg>
        </div>

        <div className="space-y-3">
          <h1
            className="font-serif text-3xl md:text-4xl font-semibold tracking-tight"
            style={{ fontFamily: "var(--font-serif)" }}
            data-testid="error-title"
          >
            {t("title")}
          </h1>
          <p
            className="font-sans text-base md:text-lg text-[var(--color-ink)]/70"
            style={{ fontFamily: "var(--font-sans)" }}
            data-testid="error-subtitle"
          >
            {t("subtitle")}
          </p>
        </div>

        <div className="flex flex-col sm:flex-row gap-3 justify-center">
          <Button
            onClick={reset}
            size="lg"
            data-testid="error-retry-button"
          >
            {t("retry")}
          </Button>
          <Button asChild variant="outline" size="lg">
            <Link href="/" data-testid="error-home-link">
              {t("backHome")}
            </Link>
          </Button>
        </div>
      </div>
    </main>
  );
}
