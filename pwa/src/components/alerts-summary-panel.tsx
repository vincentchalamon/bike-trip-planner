"use client";

import { useMemo } from "react";
import { useTranslations } from "next-intl";
import { AlertBadge } from "@/components/alert-badge";
import type { StageData } from "@/lib/validation/schemas";

interface AlertsSummaryPanelProps {
  stages: StageData[];
}

export function AlertsSummaryPanel({ stages }: AlertsSummaryPanelProps) {
  const t = useTranslations("alertsSummary");

  const { alerts, suggestions } = useMemo(() => {
    const all = stages.flatMap((stage) => stage.alerts);

    const deduped = all.filter(
      (alert, index, arr) =>
        arr.findIndex(
          (a) => a.message === alert.message && a.type === alert.type,
        ) === index,
    );

    return {
      alerts: deduped.filter(
        (a) => a.type === "critical" || a.type === "warning",
      ),
      suggestions: deduped.filter((a) => a.type === "nudge"),
    };
  }, [stages]);

  if (alerts.length === 0 && suggestions.length === 0) return null;

  return (
    <div
      className="grid gap-4 sm:grid-cols-2"
      data-testid="alerts-summary-panel"
    >
      {alerts.length > 0 && (
        <section aria-labelledby="alerts-summary-heading">
          <h2
            id="alerts-summary-heading"
            className="text-sm font-semibold text-foreground mb-2"
          >
            {t("alertsTitle")}
          </h2>
          <div className="flex flex-col gap-1.5">
            {alerts.map((alert, index) => (
              <AlertBadge
                key={`alert-${alert.type}-${index}`}
                type={alert.type}
                message={alert.message}
              />
            ))}
          </div>
        </section>
      )}

      {suggestions.length > 0 && (
        <section aria-labelledby="suggestions-summary-heading">
          <h2
            id="suggestions-summary-heading"
            className="text-sm font-semibold text-foreground mb-2"
          >
            {t("suggestionsTitle")}
          </h2>
          <div className="flex flex-col gap-1.5">
            {suggestions.map((suggestion, index) => (
              <AlertBadge
                key={`suggestion-${index}`}
                type={suggestion.type}
                message={suggestion.message}
              />
            ))}
          </div>
        </section>
      )}
    </div>
  );
}
