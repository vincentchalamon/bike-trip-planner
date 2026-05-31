"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Switch } from "@/components/ui/switch";

type CookieModalProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Current analytics opt-in, used to initialise the switch. */
  initialAnalytics: boolean;
  onSave: (analytics: boolean) => void;
  onAcceptAll: () => void;
  onRejectAll: () => void;
};

/**
 * Granularity modal letting the user toggle the analytics category and save
 * fine-grained preferences. Technical cookies are always on and not toggleable.
 */
export function CookieModal({
  open,
  onOpenChange,
  initialAnalytics,
  onSave,
  onAcceptAll,
  onRejectAll,
}: CookieModalProps) {
  const t = useTranslations("cookies");
  const [analytics, setAnalytics] = useState(initialAnalytics);

  // The modal stays mounted across open/close cycles, so reset the switch to
  // the recorded consent whenever it reopens — otherwise an unsaved toggle
  // dismissed via "×" would leak into the next open.
  useEffect(() => {
    if (open) setAnalytics(initialAnalytics);
  }, [open, initialAnalytics]);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent data-testid="cookie-modal" className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("modal.title")}</DialogTitle>
          <DialogDescription>{t("modal.description")}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4 py-2">
          <div
            className="flex items-start justify-between gap-4 rounded-lg border p-4"
            data-testid="cookie-category-technical"
          >
            <div className="flex flex-col gap-1">
              <span className="text-sm font-medium">
                {t("modal.technical.title")}
              </span>
              <span className="text-muted-foreground text-sm">
                {t("modal.technical.description")}
              </span>
            </div>
            <Switch
              checked
              disabled
              aria-label={t("modal.technical.title")}
              data-testid="cookie-toggle-technical"
            />
          </div>

          <div
            className="flex items-start justify-between gap-4 rounded-lg border p-4"
            data-testid="cookie-category-analytics"
          >
            <div className="flex flex-col gap-1">
              <span className="text-sm font-medium">
                {t("modal.analytics.title")}
              </span>
              <span className="text-muted-foreground text-sm">
                {t("modal.analytics.description")}
              </span>
            </div>
            <Switch
              checked={analytics}
              onCheckedChange={setAnalytics}
              aria-label={t("modal.analytics.title")}
              data-testid="cookie-toggle-analytics"
            />
          </div>
        </div>

        <Link
          href="/privacy"
          className="text-muted-foreground hover:text-foreground text-sm underline underline-offset-4 transition-colors"
          data-testid="cookie-modal-privacy-link"
        >
          {t("privacyLink")}
        </Link>

        <DialogFooter className="gap-2">
          <Button
            variant="ghost"
            onClick={onRejectAll}
            data-testid="cookie-modal-reject"
          >
            {t("rejectAll")}
          </Button>
          <Button
            variant="outline"
            onClick={onAcceptAll}
            data-testid="cookie-modal-accept"
          >
            {t("acceptAll")}
          </Button>
          <Button
            onClick={() => onSave(analytics)}
            data-testid="cookie-modal-save"
          >
            {t("modal.save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
