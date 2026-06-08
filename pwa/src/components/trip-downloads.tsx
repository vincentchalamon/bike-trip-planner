"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "@/components/ui/sonner";
import { Download, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { downloadTripFile, downloadSharedTripFile } from "@/lib/api/client";
import { useShareContext } from "@/lib/share-context";

interface TripDownloadsProps {
  tripId: string | undefined;
  tripTitle: string;
}

/**
 * Whole-trip download controls for the top bar: GPX and FIT, each merging all
 * stages into a single file. Mirrors the per-stage {@link StageDownloads} two-
 * button pattern. In the shared (read-only) view it downloads via short code.
 */
export function TripDownloads({ tripId, tripTitle }: TripDownloadsProps) {
  const t = useTranslations("tripSummary");
  const share = useShareContext();
  const [downloading, setDownloading] = useState<"gpx" | "fit" | false>(false);

  async function handleDownload(format: "gpx" | "fit") {
    if (!tripId && !share) return;
    setDownloading(format);
    try {
      if (share) {
        await downloadSharedTripFile(share.shortCode, share.title, format);
      } else {
        await downloadTripFile(tripId!, tripTitle, format);
      }
    } catch {
      toast.error(t("downloadFailed"));
    } finally {
      setDownloading(false);
    }
  }

  const disabled = (!tripId && !share) || !!downloading;

  return (
    <>
      <Button
        variant="ghost"
        size="sm"
        className="h-9 gap-1 px-2 cursor-pointer"
        disabled={disabled}
        onClick={() => void handleDownload("gpx")}
        aria-label={t("downloadGpx")}
        title={t("downloadGpx")}
        data-testid="trip-download-gpx"
      >
        {downloading === "gpx" ? (
          <Loader2 className="h-4 w-4 animate-spin" />
        ) : (
          <Download className="h-4 w-4" />
        )}
        <span className="text-xs font-medium">GPX</span>
      </Button>
      <Button
        variant="ghost"
        size="sm"
        className="h-9 gap-1 px-2 cursor-pointer"
        disabled={disabled}
        onClick={() => void handleDownload("fit")}
        aria-label={t("downloadFit")}
        title={t("downloadFit")}
        data-testid="trip-download-fit"
      >
        {downloading === "fit" ? (
          <Loader2 className="h-4 w-4 animate-spin" />
        ) : (
          <Download className="h-4 w-4" />
        )}
        <span className="text-xs font-medium">FIT</span>
      </Button>
    </>
  );
}
