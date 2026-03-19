"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Download, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { downloadTripGpx } from "@/lib/api/client";

interface TripDownloadsProps {
  tripId: string | undefined;
  tripTitle: string;
}

export function TripDownloads({ tripId, tripTitle }: TripDownloadsProps) {
  const t = useTranslations("tripSummary");
  const [downloading, setDownloading] = useState(false);

  async function handleDownload() {
    if (!tripId) return;
    setDownloading(true);
    try {
      await downloadTripGpx(tripId, tripTitle);
    } catch {
      toast.error(t("downloadFailed"));
    } finally {
      setDownloading(false);
    }
  }

  return (
    <Button
      variant="ghost"
      size="icon"
      className="h-9 w-9 cursor-pointer"
      disabled={!tripId || downloading}
      onClick={() => void handleDownload()}
      aria-label={t("downloadGpx")}
      title={t("downloadGpx")}
    >
      {downloading ? (
        <Loader2 className="h-4 w-4 animate-spin" />
      ) : (
        <Download className="h-4 w-4" />
      )}
    </Button>
  );
}
