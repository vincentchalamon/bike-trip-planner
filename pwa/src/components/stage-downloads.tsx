"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Download, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { downloadStageFile, downloadSharedStageFile } from "@/lib/api/client";
import { useShareContext } from "@/lib/share-context";

interface StageDownloadsProps {
  tripId: string | undefined;
  stageIndex: number;
  dayNumber: number;
}

export function StageDownloads({
  tripId,
  stageIndex,
  dayNumber,
}: StageDownloadsProps) {
  const t = useTranslations("stage");
  const share = useShareContext();
  const [downloading, setDownloading] = useState<"gpx" | "fit" | false>(false);

  async function handleDownload(format: "gpx" | "fit") {
    if (!tripId && !share) return;
    setDownloading(format);
    try {
      if (share) {
        await downloadSharedStageFile(
          share.shortCode,
          stageIndex,
          format,
          dayNumber,
        );
      } else {
        await downloadStageFile(tripId!, stageIndex, format, dayNumber);
      }
    } catch {
      toast.error(t("downloadFailed"));
    } finally {
      setDownloading(false);
    }
  }

  return (
    <>
      <Button
        variant="ghost"
        size="sm"
        className="h-6 gap-1 text-muted-icon px-1.5 cursor-pointer"
        disabled={(!tripId && !share) || !!downloading}
        onClick={() => void handleDownload("gpx")}
        aria-label={t("downloadGpx", { dayNumber })}
        title={t("downloadGpx", { dayNumber })}
      >
        {downloading === "gpx" ? (
          <Loader2 className="h-3.5 w-3.5 animate-spin" />
        ) : (
          <Download className="h-3.5 w-3.5" />
        )}
        <span className="text-[10px] font-medium">GPX</span>
      </Button>
      <Button
        variant="ghost"
        size="sm"
        className="h-6 gap-1 text-muted-icon px-1.5 cursor-pointer"
        disabled={(!tripId && !share) || !!downloading}
        onClick={() => void handleDownload("fit")}
        aria-label={t("downloadFit", { dayNumber })}
        title={t("downloadFit", { dayNumber })}
      >
        {downloading === "fit" ? (
          <Loader2 className="h-3.5 w-3.5 animate-spin" />
        ) : (
          <Download className="h-3.5 w-3.5" />
        )}
        <span className="text-[10px] font-medium">FIT</span>
      </Button>
    </>
  );
}
