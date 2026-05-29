"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Download, Loader2 } from "lucide-react";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { toast } from "@/components/ui/sonner";
import { downloadAccountExport } from "@/lib/api/client";

/**
 * "Mes données (RGPD)" section: downloads the user's full data archive as JSON
 * via `GET /users/me/export`.
 */
export function DataSection() {
  const t = useTranslations("accountSettings.data");
  const [isDownloading, setIsDownloading] = useState(false);

  async function handleDownload() {
    setIsDownloading(true);
    try {
      await downloadAccountExport();
    } catch {
      toast.error(t("downloadFailed"));
    } finally {
      setIsDownloading(false);
    }
  }

  return (
    <Card data-testid="data-section">
      <CardHeader>
        <CardTitle>{t("title")}</CardTitle>
        <CardDescription>{t("description")}</CardDescription>
      </CardHeader>
      <CardContent>
        <Button
          variant="outline"
          className="gap-2 cursor-pointer"
          onClick={() => void handleDownload()}
          disabled={isDownloading}
          data-testid="export-data-button"
        >
          {isDownloading ? (
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
          ) : (
            <Download className="h-4 w-4" aria-hidden="true" />
          )}
          {isDownloading ? t("downloading") : t("download")}
        </Button>
      </CardContent>
    </Card>
  );
}
