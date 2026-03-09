"use client";

import { useEffect } from "react";
import { useTranslations } from "next-intl";

interface ErrorBoundaryProps {
  error: Error & { digest?: string };
  reset: () => void;
}

export default function ErrorBoundary({ error, reset }: ErrorBoundaryProps) {
  const t = useTranslations("errors");

  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <div className="text-center space-y-4 max-w-md">
        <h2 className="text-2xl font-semibold">{t("unexpectedError")}</h2>
        <p className="text-muted-foreground">
          {process.env.NODE_ENV === "development"
            ? error.message
            : t("unexpectedError")}
        </p>
        <button
          onClick={reset}
          className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
        >
          {t("retry")}
        </button>
      </div>
    </div>
  );
}
