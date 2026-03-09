"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Download, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { downloadStageFile } from "@/lib/api/client";

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
  const [downloading, setDownloading] = useState<"gpx" | "fit" | false>(false);

  async function handleDownload(format: "gpx" | "fit") {
    if (!tripId) return;
    setDownloading(format);
    try {
      await downloadStageFile(tripId, stageIndex, format, dayNumber);
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
        disabled={!tripId || !!downloading}
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
        disabled={!tripId || !!downloading}
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
