"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { logger } from "@/lib/logger";

export interface ErrorBoundaryProps {
  error: Error & { digest?: string };
  reset: () => void;
}

/**
 * Shared content of the route error boundaries — the root `error.tsx` (app
 * chrome) and `(public)/error.tsx` (public chrome). Renders the error card; the
 * surrounding header + footer come from the wrapping {@link SiteChrome}, so an
 * error thrown on a public page keeps the public header instead of the
 * authenticated one (recette #649).
 */
export function ErrorBoundaryContent({ error, reset }: ErrorBoundaryProps) {
  const t = useTranslations("errorPages.error");

  useEffect(() => {
    // Log the original error for diagnostics; the user only sees a generic message.
    logger.error("App error boundary triggered", {
      error,
      digest: error.digest,
    });
  }, [error]);

  // Captured once at mount so it stays stable across re-renders: shown alongside
  // the request_id so a user can quote when the error occurred.
  const [timestamp] = useState(() => new Date().toISOString());

  return (
    <main
      className="flex min-h-[60vh] flex-1 items-center justify-center px-4 py-12 bg-[var(--color-surface)] text-[var(--color-ink)]"
      data-testid="error-page"
    >
      <div className="text-center space-y-6 max-w-md">
        <div
          className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400"
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
          <p
            className="text-xs font-semibold uppercase tracking-[0.15em] text-red-600 dark:text-red-400"
            data-testid="error-badge"
          >
            {t("badge")}
          </p>
          <h1
            className="font-serif text-3xl md:text-4xl font-semibold tracking-tight"
            data-testid="error-title"
          >
            {t("title")}
          </h1>
          <p
            className="font-sans text-base md:text-lg text-[var(--color-ink)]/70"
            data-testid="error-subtitle"
          >
            {t("subtitle")}
          </p>
        </div>

        {error.digest && (
          <div
            className="rounded-lg border border-border bg-[var(--color-surface)] p-3 text-left font-mono text-xs text-[var(--color-ink)]/60"
            data-testid="error-request-id"
          >
            request_id: {error.digest}
            <br />
            timestamp: {timestamp}
          </div>
        )}

        <div className="flex flex-col sm:flex-row gap-3 justify-center">
          <Button onClick={reset} size="lg" data-testid="error-retry-button">
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
