"use client";

import { useState, useMemo } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FileText, Copy, Check } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { buildTripText } from "@/lib/text-export";
import type { StageData } from "@/lib/validation/schemas";

interface TextExportButtonProps {
  title: string;
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  sourceUrl: string;
  stages: StageData[];
  startDate: string | null;
}

export function TextExportButton({
  title,
  totalDistance,
  totalElevation,
  totalElevationLoss,
  sourceUrl,
  stages,
  startDate,
}: TextExportButtonProps) {
  const t = useTranslations("textExport");
  const [open, setOpen] = useState(false);
  const [copied, setCopied] = useState(false);

  const text = useMemo(
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
          totalDistance: t("totalDistance"),
          totalElevation: t("totalElevation"),
        },
      }),
    [title, totalDistance, totalElevation, totalElevationLoss, sourceUrl, stages, startDate, t],
  );

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      toast.success(t("copySuccess"));
      setTimeout(() => setCopied(false), 2000);
    } catch {
      toast.error(t("copyFailed"));
    }
  }

  return (
    <>
      <Button
        variant="ghost"
        size="sm"
        className="h-8 gap-1.5 text-muted-foreground cursor-pointer"
        onClick={() => setOpen(true)}
        aria-label={t("openLabel")}
        title={t("openLabel")}
        data-testid="text-export-button"
      >
        <FileText className="h-4 w-4" />
        <span className="text-xs">{t("button")}</span>
      </Button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>{t("dialogTitle")}</DialogTitle>
          </DialogHeader>

          <div className="whitespace-pre-wrap break-words rounded-md bg-muted p-4 text-sm leading-relaxed max-h-[60vh] overflow-y-auto">
            {text.split("\n").map((line, i) => (
              <p key={i} className="min-h-[1em]">
                {line.split(/(\*[^*]+\*)/).map((part, j) =>
                  /^\*[^*]+\*$/.test(part) ? (
                    <strong key={j}>{part.slice(1, -1)}</strong>
                  ) : (
                    part
                  ),
                )}
              </p>
            ))}
          </div>

          <div className="flex justify-end">
            <Button
              onClick={() => void handleCopy()}
              className="gap-2 cursor-pointer"
              data-testid="text-export-copy-button"
            >
              {copied ? (
                <Check className="h-4 w-4" />
              ) : (
                <Copy className="h-4 w-4" />
              )}
              {copied ? t("copied") : t("copy")}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
}
