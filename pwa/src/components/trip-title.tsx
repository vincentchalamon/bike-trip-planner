"use client";

import { useState, useEffect, useRef } from "react";
import { useTranslations } from "next-intl";
import { X } from "lucide-react";
import { EditableField } from "@/components/editable-field";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useTripStore } from "@/store/trip-store";

import { getSuggestionName } from "@/lib/trip-utils";

interface TripTitleProps {
  title: string;
  onChange: (title: string) => void;
  showSuggestion?: boolean;
  isLoading?: boolean;
}

export function TripTitle({
  title,
  onChange,
  showSuggestion,
  isLoading,
}: TripTitleProps) {
  const t = useTranslations("tripTitle");
  const tripId = useTripStore((s) => s.trip?.id ?? null);
  const [dismissed, setDismissed] = useState(false);
  const [suggestedName] = useState(() => getSuggestionName(title));
  const hasShown = useRef(false);

  // Persist the accept/dismiss decision per trip so the suggestion is not
  // re-proposed after a reload (recette #649).
  const storageKey = tripId ? `btp.title-suggestion-dismissed.${tripId}` : null;

  useEffect(() => {
    if (!storageKey) return;
    try {
      if (window.localStorage.getItem(storageKey) === "1") setDismissed(true);
    } catch {
      /* localStorage unavailable (private mode, SSR) — keep showing once */
    }
  }, [storageKey]);

  const dismiss = () => {
    setDismissed(true);
    if (!storageKey) return;
    try {
      window.localStorage.setItem(storageKey, "1");
    } catch {
      /* ignore storage write errors */
    }
  };

  const shouldShow = showSuggestion && !dismissed && !hasShown.current;

  useEffect(() => {
    if (shouldShow) {
      hasShown.current = true;
    }
  }, [shouldShow]);

  const showBanner = showSuggestion && !dismissed && hasShown.current;

  if (isLoading) {
    return (
      <div>
        <Skeleton className="h-8 w-64" data-testid="trip-title-skeleton" />
      </div>
    );
  }

  return (
    <div>
      <EditableField
        value={title}
        onChange={onChange}
        className="text-xl md:text-2xl font-semibold"
        placeholder={t("placeholder")}
        aria-label={t("ariaLabel")}
        data-testid="trip-title"
      />

      {showBanner && (
        <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground bg-brand/5 rounded-md px-3 py-2">
          <span>{t("suggestion", { name: suggestedName })}</span>
          <Button
            variant="outline"
            size="sm"
            className="h-6 text-xs cursor-pointer"
            onClick={() => {
              onChange(suggestedName);
              dismiss();
            }}
          >
            {t("apply")}
          </Button>
          <Button
            variant="ghost"
            size="icon"
            className="h-6 w-6 cursor-pointer"
            onClick={dismiss}
            aria-label={t("dismissSuggestion")}
          >
            <X className="h-3.5 w-3.5" />
          </Button>
        </div>
      )}
    </div>
  );
}
