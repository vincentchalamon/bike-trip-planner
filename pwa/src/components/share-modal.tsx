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
import { renderInfographic, downloadInfographicPng } from "@/lib/infographic";
import { buildTripText } from "@/lib/text-export";
import { createTripShare, revokeTripShare } from "@/lib/api/client";
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
  const [shareId, setShareId] = useState<string | null>(null);
  const [hasRevoked, setHasRevoked] = useState(false);
  const [isRecreatingLink, setIsRecreatingLink] = useState(false);
  const isCreatingLink = (open && !shareUrl && !hasRevoked) || isRecreatingLink;
  const [isRevokingLink, setIsRevokingLink] = useState(false);
  const [linkCopied, setLinkCopied] = useState(false);
  const [textCopied, setTextCopied] = useState(false);

  const canvasRef = useRef<HTMLCanvasElement>(null);

  // Create share link on first open
  useEffect(() => {
    if (!open || shareUrl || hasRevoked) return;

    let cancelled = false;
    createTripShare(tripId).then((result) => {
      if (cancelled) return;
      if (result) {
        setShareUrl(result.shareUrl);
        setShareId(result.id);
      } else {
        toast.error(t("linkCreateFailed"));
      }
    });

    return () => {
      cancelled = true;
    };
  }, [open, tripId, shareUrl, hasRevoked, t]);

  // Render infographic when dialog opens and data is ready
  useEffect(() => {
    if (!open) return;
    // Small delay to ensure canvas is mounted
    const timer = setTimeout(() => {
      const canvas = canvasRef.current;
      if (!canvas) return;

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
          days: t("statDays"),
          budget: t("statBudget"),
          difficulty: t("statDifficulty"),
          weather: t("statWeather"),
          difficultyEasy: tStage("difficultyEasy"),
          difficultyMedium: tStage("difficultyMedium"),
          difficultyHard: tStage("difficultyHard"),
          powered: t("poweredBy"),
        },
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

  const handleCopyLink = useCallback(async () => {
    if (!shareUrl) return;
    try {
      await navigator.clipboard.writeText(shareUrl);
      setLinkCopied(true);
      toast.success(t("linkCopied"));
      setTimeout(() => setLinkCopied(false), 2000);
    } catch {
      toast.error(t("linkCopyFailed"));
    }
  }, [shareUrl, t]);

  const handleRevokeLink = useCallback(async () => {
    if (!shareId) return;
    setIsRevokingLink(true);
    const ok = await revokeTripShare(tripId, shareId);
    setIsRevokingLink(false);
    if (ok) {
      setHasRevoked(true);
      setShareUrl(null);
      setShareId(null);
      toast.success(t("linkRevoked"));
    } else {
      toast.error(t("linkRevokeFailed"));
    }
  }, [tripId, shareId, t]);

  const handleDownloadPng = useCallback(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const safeName = title.trim().replace(/[^a-z0-9\-_]/gi, "-") || "trip";
    downloadInfographicPng(canvas, `${safeName}-infographic.png`);
  }, [title]);

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

  // Build the formatted text including the share link if available
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
      <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto">
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

          {isCreatingLink ? (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              {t("linkCreating")}
            </div>
          ) : shareUrl ? (
            <div className="flex flex-col gap-2">
              <div className="flex items-center gap-2">
                <input
                  type="text"
                  readOnly
                  value={shareUrl}
                  className="flex-1 rounded-md border bg-muted px-3 py-2 text-sm select-all"
                  onClick={(e) => (e.target as HTMLInputElement).select()}
                  data-testid="share-link-input"
                />
                <Button
                  variant="outline"
                  size="icon"
                  className="shrink-0 cursor-pointer"
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
                <Button
                  variant="ghost"
                  size="icon"
                  className="shrink-0 text-destructive cursor-pointer"
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
              <p className="text-xs text-muted-foreground">
                {t("linkReadOnlyNote")}
              </p>
            </div>
          ) : (
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                className="cursor-pointer"
                onClick={() => {
                  setHasRevoked(false);
                  setIsRecreatingLink(true);
                  void createTripShare(tripId).then((result) => {
                    setIsRecreatingLink(false);
                    if (result) {
                      setShareUrl(result.shareUrl);
                      setShareId(result.id);
                    } else {
                      toast.error(t("linkCreateFailed"));
                    }
                  });
                }}
                data-testid="share-create-link-button"
              >
                {t("createLink")}
              </Button>
            </div>
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
              style={{ maxWidth: "600px", aspectRatio: "600 / 400" }}
            >
              <canvas
                ref={canvasRef}
                className="block w-full h-full"
                data-testid="share-infographic-canvas"
              />
            </div>
            <Button
              variant="outline"
              size="sm"
              className="gap-2 cursor-pointer"
              onClick={handleDownloadPng}
              data-testid="share-download-png-button"
            >
              <Download className="h-4 w-4" />
              {t("downloadPng")}
            </Button>
          </div>
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
  // Split on bold markers and URLs
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
