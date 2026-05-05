"use client";

import type React from "react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  Link as LinkIcon,
  Copy,
  Check,
  Download,
  Image as ImageIcon,
  FileText,
  Loader2,
  Trash2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { Separator } from "@/components/ui/separator";
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import {
  renderInfographic,
  downloadInfographicPng,
  CARD_WIDTH,
  CARD_HEIGHT,
} from "@/lib/infographic";
import { useExportInfographic } from "@/hooks/use-export-infographic";
import { MAX_STAGE_LIST } from "@/lib/infographic-square";
import { buildTripText } from "@/lib/text-export";
import {
  buildShareUrl,
  getTripShare,
  createTripShare,
  revokeTripShare,
} from "@/lib/api/client";
import type { StageData } from "@/lib/validation/schemas";

interface ShareModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  tripId: string;
  title: string;
  sourceUrl: string;
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  stages: StageData[];
  startDate: string | null;
  endDate: string | null;
  estimatedBudgetMin: number;
  estimatedBudgetMax: number;
}

export function ShareModal({
  open,
  onOpenChange,
  tripId,
  title,
  sourceUrl,
  totalDistance,
  totalElevation,
  totalElevationLoss,
  stages,
  startDate,
  endDate,
  estimatedBudgetMin,
  estimatedBudgetMax,
}: ShareModalProps) {
  const t = useTranslations("share");
  const tStage = useTranslations("stage");
  const tTextExport = useTranslations("textExport");

  const [shareUrl, setShareUrl] = useState<string | null>(null);
  const [isLoadingLink, setIsLoadingLink] = useState(false);
  const [isCreatingLink, setIsCreatingLink] = useState(false);
  const [isRevokingLink, setIsRevokingLink] = useState(false);
  const [hasFetched, setHasFetched] = useState(false);
  const [linkCopied, setLinkCopied] = useState(false);
  const [textCopied, setTextCopied] = useState(false);
  const [isRenderingInfographic, setIsRenderingInfographic] = useState(false);

  const canvasRef = useRef<HTMLCanvasElement>(null);
  const {
    canvasRef: squareCanvasRef,
    exportPng: exportSquarePng,
    isExporting: isExportingSquare,
  } = useExportInfographic();

  // Fetch existing active share on first open
  useEffect(() => {
    if (!open || hasFetched) return;

    let cancelled = false;
    setIsLoadingLink(true);
    getTripShare(tripId)
      .then((result) => {
        if (cancelled) return;
        if (result) {
          setShareUrl(buildShareUrl(result.shortCode ?? ""));
        }
      })
      .catch(() => {
        // 404 = no active share, which is fine
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoadingLink(false);
          setHasFetched(true);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [open, tripId, hasFetched]);

  // Render infographic when dialog opens and data is ready
  useEffect(() => {
    if (!open) return;
    const timer = setTimeout(() => {
      const canvas = canvasRef.current;
      if (!canvas) return;

      setIsRenderingInfographic(true);
      renderInfographic(canvas, {
        title,
        totalDistance,
        totalElevation,
        totalElevationLoss,
        stages,
        startDate,
        endDate,
        estimatedBudgetMin,
        estimatedBudgetMax,
        labels: {
          distance: t("statDistance"),
          elevation: t("statElevation"),
          dates: t("statDates"),
          budget: t("statBudget"),
          difficulty: t("statDifficulty"),
          weather: t("statWeather"),
          difficultyEasy: tStage("difficultyEasy"),
          difficultyMedium: tStage("difficultyMedium"),
          difficultyHard: tStage("difficultyHard"),
          powered: t("poweredBy"),
        },
      })
        .catch((err: unknown) => {
          console.error("[infographic] render failed", err);
        })
        .finally(() => {
          setIsRenderingInfographic(false);
        });
    }, 100);
    return () => clearTimeout(timer);
  }, [
    open,
    title,
    totalDistance,
    totalElevation,
    totalElevationLoss,
    stages,
    startDate,
    endDate,
    estimatedBudgetMin,
    estimatedBudgetMax,
    t,
    tStage,
  ]);

  const handleCreateLink = useCallback(async () => {
    setIsCreatingLink(true);
    try {
      const result = await createTripShare(tripId);
      if (result) {
        setShareUrl(buildShareUrl(result.shortCode ?? ""));
      } else {
        toast.error(t("linkCreateFailed"));
      }
    } catch {
      toast.error(t("linkCreateFailed"));
    } finally {
      setIsCreatingLink(false);
    }
  }, [tripId, t]);

  const handleCopyLink = useCallback(async () => {
    if (!shareUrl) return;
    try {
      await navigator.clipboard.writeText(shareUrl);
      setLinkCopied(true);
      setTimeout(() => setLinkCopied(false), 2000);
    } catch {
      toast.error(t("linkCopyFailed"));
    }
  }, [shareUrl, t]);

  const handleRevokeLink = useCallback(async () => {
    setIsRevokingLink(true);
    try {
      const ok = await revokeTripShare(tripId);
      if (ok) {
        setShareUrl(null);
        toast.success(t("linkRevoked"));
      } else {
        toast.error(t("linkRevokeFailed"));
      }
    } catch {
      toast.error(t("linkRevokeFailed"));
    } finally {
      setIsRevokingLink(false);
    }
  }, [tripId, t]);

  const handleDownloadPng = useCallback(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const safeName = title.trim().replace(/[^a-z0-9\-_]/gi, "-") || "trip";
    downloadInfographicPng(canvas, `${safeName}-infographic.png`);
  }, [title]);

  const handleDownloadSquarePng = useCallback(() => {
    const safeName = title.trim().replace(/[^a-z0-9\-_]/gi, "-") || "trip";
    const activeCount = stages.filter((s) => !s.isRestDay).length;
    const extra = Math.max(0, activeCount - MAX_STAGE_LIST);
    void exportSquarePng(
      {
        title,
        totalDistance,
        totalElevation,
        stages,
        estimatedBudgetMin,
        estimatedBudgetMax,
        labels: {
          distance: t("statDistance"),
          elevation: t("statElevation"),
          days: t("squareStatDays"),
          budget: t("statBudget"),
          stagesHeading: t("squareStagesHeading"),
          restDay: "—",
          more: t("squareMoreStages", { count: extra }),
          dayPrefix: t("squareDayPrefix"),
          poweredBy: t("poweredBy"),
        },
      },
      `${safeName}-infographic-square.png`,
    );
  }, [
    title,
    totalDistance,
    totalElevation,
    stages,
    estimatedBudgetMin,
    estimatedBudgetMax,
    exportSquarePng,
    t,
  ]);

  const tripText = useMemo(
    () =>
      buildTripText({
        title,
        totalDistance,
        totalElevation,
        totalElevationLoss,
        sourceUrl,
        stages,
        startDate,
        labels: {
          totalDistance: tTextExport("totalDistance"),
          totalElevation: tTextExport("totalElevation"),
        },
      }),
    [
      title,
      totalDistance,
      totalElevation,
      totalElevationLoss,
      sourceUrl,
      stages,
      startDate,
      tTextExport,
    ],
  );

  const fullText = useMemo(() => {
    if (!shareUrl) return tripText;
    return `${tripText}\n\n${t("viewOnline")}: ${shareUrl}`;
  }, [tripText, shareUrl, t]);

  const handleCopyText = useCallback(async () => {
    try {
      await navigator.clipboard.writeText(fullText);
      setTextCopied(true);
      toast.success(t("textCopied"));
      setTimeout(() => setTextCopied(false), 2000);
    } catch {
      toast.error(t("textCopyFailed"));
    }
  }, [fullText, t]);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto [&>*]:min-w-0">
        <DialogHeader>
          <DialogTitle>{t("title")}</DialogTitle>
          <DialogDescription>{t("description")}</DialogDescription>
        </DialogHeader>

        {/* Section 1: Shareable link */}
        <section aria-labelledby="share-link-heading">
          <h3
            id="share-link-heading"
            className="flex items-center gap-2 text-sm font-medium mb-2"
          >
            <LinkIcon className="h-4 w-4" />
            {t("linkTitle")}
          </h3>

          {isLoadingLink ? (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              {t("linkCreating")}
            </div>
          ) : shareUrl ? (
            <div className="flex flex-col gap-2">
              <TooltipProvider>
                <div className="flex items-center gap-2">
                  <div className="flex-1 min-w-0">
                    <button
                      onClick={() => void handleCopyLink()}
                      className="block w-full text-left text-sm text-brand underline underline-offset-2 hover:no-underline truncate cursor-pointer"
                      data-testid="share-link-text"
                    >
                      {shareUrl}
                    </button>
                  </div>

                  <Tooltip open={linkCopied}>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="icon"
                        className="shrink-0"
                        onClick={() => void handleCopyLink()}
                        aria-label={t("copyLink")}
                        data-testid="share-copy-link-button"
                      >
                        {linkCopied ? (
                          <Check className="h-4 w-4" />
                        ) : (
                          <Copy className="h-4 w-4" />
                        )}
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                      {t("linkCopied")}
                    </TooltipContent>
                  </Tooltip>

                  <Button
                    variant="ghost"
                    size="icon"
                    className="shrink-0 text-destructive"
                    onClick={() => void handleRevokeLink()}
                    disabled={isRevokingLink}
                    aria-label={t("revokeLink")}
                    data-testid="share-revoke-link-button"
                  >
                    {isRevokingLink ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <Trash2 className="h-4 w-4" />
                    )}
                  </Button>
                </div>
              </TooltipProvider>
              <p className="text-xs text-muted-foreground">
                {t("linkReadOnlyNote")}
              </p>
            </div>
          ) : (
            <Button
              variant="outline"
              size="sm"
              className="cursor-pointer"
              onClick={() => void handleCreateLink()}
              disabled={isCreatingLink}
              data-testid="share-create-link-button"
            >
              {isCreatingLink && <Loader2 className="h-4 w-4 animate-spin" />}
              {t("createLink")}
            </Button>
          )}
        </section>

        <Separator />

        {/* Section 2: Infographic */}
        <section aria-labelledby="share-infographic-heading">
          <h3
            id="share-infographic-heading"
            className="flex items-center gap-2 text-sm font-medium mb-2"
          >
            <ImageIcon className="h-4 w-4" />
            {t("infographicTitle")}
          </h3>

          <div className="flex flex-col items-center gap-3">
            <div
              className="rounded-lg overflow-hidden border shadow-sm w-full"
              style={{
                maxWidth: `${CARD_WIDTH}px`,
                aspectRatio: `${CARD_WIDTH} / ${CARD_HEIGHT}`,
              }}
            >
              <canvas
                ref={canvasRef}
                className="block w-full h-full"
                data-testid="share-infographic-canvas"
              />
            </div>
            <div className="flex flex-wrap items-center justify-center gap-2">
              <Button
                variant="outline"
                size="sm"
                className="gap-2 cursor-pointer"
                onClick={handleDownloadPng}
                disabled={isRenderingInfographic}
                data-testid="share-download-png-button"
              >
                {isRenderingInfographic ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <Download className="h-4 w-4" />
                )}
                {t("downloadPng")}
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="gap-2 cursor-pointer"
                onClick={handleDownloadSquarePng}
                disabled={isExportingSquare}
                data-testid="share-download-square-png-button"
                title={t("downloadSquarePngHint")}
              >
                {isExportingSquare ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <Download className="h-4 w-4" />
                )}
                {t("downloadSquarePng")}
              </Button>
            </div>
          </div>
          {/* Off-screen canvas used to render the 1080×1080 export. Kept in
              the DOM (positioned off-screen) so the canvas context survives
              between exports without remounts. */}
          <canvas
            ref={squareCanvasRef}
            data-testid="share-infographic-square-canvas"
            aria-hidden="true"
            style={{
              position: "absolute",
              left: "-99999px",
              top: 0,
              width: 1,
              height: 1,
              pointerEvents: "none",
            }}
          />
        </section>

        <Separator />

        {/* Section 3: Formatted text */}
        <section aria-labelledby="share-text-heading">
          <h3
            id="share-text-heading"
            className="flex items-center gap-2 text-sm font-medium mb-2"
          >
            <FileText className="h-4 w-4" />
            {t("textTitle")}
          </h3>

          <div className="whitespace-pre-wrap break-words rounded-md bg-muted p-4 text-sm leading-relaxed max-h-[30vh] overflow-y-auto">
            {fullText.split("\n").map((line, i) => (
              <p key={i} className="min-h-[1em]">
                {renderTextLine(line)}
              </p>
            ))}
          </div>

          <p className="text-xs text-muted-foreground/70 leading-relaxed mt-2">
            {tTextExport("budgetNote")}
          </p>

          <div className="flex justify-end mt-2">
            <Button
              onClick={() => void handleCopyText()}
              variant="outline"
              size="sm"
              className="gap-2 cursor-pointer"
              data-testid="share-copy-text-button"
            >
              {textCopied ? (
                <Check className="h-4 w-4" />
              ) : (
                <Copy className="h-4 w-4" />
              )}
              {textCopied ? t("textCopiedBtn") : t("copyText")}
            </Button>
          </div>
        </section>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Render a single line of text, converting *bold* markers to <strong>
 * and URLs to clickable links.
 */
function renderTextLine(line: string): React.ReactNode[] {
  const parts = line.split(/(\*[^*]+\*|https?:\/\/[^\s,)]+)/);
  return parts.map((part, j) => {
    if (/^\*[^*]+\*$/.test(part)) {
      return <strong key={j}>{part.slice(1, -1)}</strong>;
    }
    if (/^https?:\/\//.test(part)) {
      return (
        <a
          key={j}
          href={part}
          target="_blank"
          rel="noopener noreferrer"
          className="text-brand underline hover:no-underline"
        >
          {part}
        </a>
      );
    }
    return part;
  });
}
