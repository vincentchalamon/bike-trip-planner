"use client";

import { useCallback, useId, useMemo, useState } from "react";
import { useTranslations } from "next-intl";
import { Bot, ChevronDown, ChevronUp, Sparkles } from "lucide-react";
import { AlertBadge } from "@/components/alert-badge";
import { AlertList, SEVERITY_ORDER } from "@/components/alert-list";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import { useStageAiAnalysis, useTripStore } from "@/store/trip-store";
import type { AlertData } from "@/lib/validation/schemas";

interface StageAiSummaryProps {
  stageIndex: number;
  alerts: AlertData[];
  onAddPoiWaypoint?: (poiLat: number, poiLon: number) => void;
}

/** Maximum number of alerts surfaced in the collapsed preview. */
const ALERT_PREVIEW_COUNT = 3;

function sortAlertsBySeverity(alerts: AlertData[]): AlertData[] {
  // Stable sort by severity rank — `SEVERITY_ORDER` already encodes the
  // critical → warning → nudge ranking shared with `AlertList`.
  const rank = (type: AlertData["type"]) => SEVERITY_ORDER.indexOf(type);
  return [...alerts].sort((a, b) => rank(a.type) - rank(b.type));
}

/**
 * Hybrid stage layout: AI narrative briefing + collapsible alerts section
 * (issue #306).
 *
 * Top half — narrative + insights + suggestions produced by the LLaMA pass 1
 * (`AnalyzeStageWithLlmHandler` → `aiAnalysis` on each `trip_ready` stage
 * payload). The "Appliquer les N suggestions" CTA enqueues a `pacing` batch
 * modification so the recompute is rolled into the existing batch-mode flow
 * (no immediate recompute — it joins any other pending changes).
 *
 * Bottom half — collapsible alerts. The hybrid layout collapses the section
 * by default and surfaces a flat top-3 (by severity: critical → warning →
 * nudge) preview; the user expands to reveal the full grouped `AlertList`.
 *
 * Returns `null` when no AI analysis is available so the caller can fall
 * back to the legacy fully-expanded `StageAlerts`.
 */
export function StageAiSummary({
  stageIndex,
  alerts,
  onAddPoiWaypoint,
}: StageAiSummaryProps) {
  const t = useTranslations("stageAiSummary");
  const analysis = useStageAiAnalysis(stageIndex);
  const queueModification = useTripStore((s) => s.queueModification);
  const [alertsExpanded, setAlertsExpanded] = useState(false);
  const alertsId = useId();

  const sortedAlerts = useMemo(() => sortAlertsBySeverity(alerts), [alerts]);
  const previewAlerts = sortedAlerts.slice(0, ALERT_PREVIEW_COUNT);
  const hiddenAlertsCount = Math.max(
    0,
    sortedAlerts.length - previewAlerts.length,
  );

  const suggestions = analysis?.suggestions ?? [];
  const insights = analysis?.insights ?? [];

  const handleApplySuggestions = useCallback(() => {
    if (suggestions.length === 0) return;
    queueModification({
      stageIndex: null,
      type: "pacing",
      label: t("applyQueueLabel", { dayNumber: stageIndex + 1 }),
    });
  }, [queueModification, stageIndex, suggestions.length, t]);

  if (!analysis || !analysis.narrative.trim()) return null;

  return (
    <div
      className="flex flex-col gap-3"
      data-testid={`stage-ai-summary-${stageIndex}`}
    >
      <Card className="border-brand/30 bg-brand/5">
        <CardContent className="flex flex-col gap-3 p-4">
          <div className="flex items-start gap-3">
            <span
              aria-hidden="true"
              className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand/15 text-brand"
            >
              <Bot className="h-4 w-4" />
            </span>
            <div className="flex-1 min-w-0">
              <p className="text-[11px] font-medium uppercase tracking-wider text-brand/80">
                {t("label")}
              </p>
              <p
                className="mt-1 whitespace-pre-line text-sm leading-relaxed"
                data-testid="stage-ai-summary-narrative"
              >
                {analysis.narrative}
              </p>
            </div>
          </div>

          {insights.length > 0 && (
            <section data-testid="stage-ai-summary-insights">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {t("insightsHeading")}
              </h3>
              <ul className="mt-1 list-disc space-y-1 pl-5 text-sm">
                {insights.map((insight, idx) => (
                  <li key={idx}>{insight}</li>
                ))}
              </ul>
            </section>
          )}

          {suggestions.length > 0 && (
            <section data-testid="stage-ai-summary-suggestions">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {t("suggestionsHeading")}
              </h3>
              <ul className="mt-1 list-disc space-y-1 pl-5 text-sm">
                {suggestions.map((suggestion, idx) => (
                  <li key={idx}>{suggestion}</li>
                ))}
              </ul>
              <Button
                type="button"
                variant="default"
                size="sm"
                onClick={handleApplySuggestions}
                className="mt-3 inline-flex items-center gap-2"
                data-testid="stage-ai-summary-apply"
              >
                <Sparkles className="h-3.5 w-3.5" aria-hidden="true" />
                {t("applyCta")}
              </Button>
            </section>
          )}
        </CardContent>
      </Card>

      {sortedAlerts.length > 0 && (
        <div data-testid="stage-ai-summary-alerts">
          <button
            type="button"
            className="flex w-full items-center justify-between gap-2 py-1 text-sm font-medium text-foreground hover:text-foreground/80 transition-colors"
            onClick={() => setAlertsExpanded((prev) => !prev)}
            aria-expanded={alertsExpanded}
            aria-controls={alertsId}
            data-testid="stage-ai-summary-alerts-toggle"
          >
            <span data-testid="stage-ai-summary-alerts-count">
              {t("alertsTitle", { count: sortedAlerts.length })}
            </span>
            {alertsExpanded ? (
              <ChevronUp
                className="h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
              />
            ) : (
              <ChevronDown
                className="h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
              />
            )}
          </button>

          <div id={alertsId} className="mt-2">
            {alertsExpanded ? (
              <div data-testid="stage-ai-summary-alerts-full">
                <AlertList
                  alerts={sortedAlerts}
                  onAddPoiWaypoint={onAddPoiWaypoint}
                />
              </div>
            ) : (
              <div
                className={cn("flex flex-col gap-2")}
                data-testid="stage-ai-summary-alerts-preview"
              >
                {previewAlerts.map((alert, idx) => (
                  <AlertBadge
                    key={`${alert.type}-${alert.message}-${idx}`}
                    type={alert.type}
                    message={alert.message}
                  />
                ))}
                {hiddenAlertsCount > 0 && (
                  <button
                    type="button"
                    onClick={() => setAlertsExpanded(true)}
                    className="self-start text-xs font-medium text-brand hover:text-brand/80 transition-colors cursor-pointer"
                    data-testid="stage-ai-summary-alerts-show-more"
                  >
                    {t("showMoreAlerts", { count: hiddenAlertsCount })}
                  </button>
                )}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
