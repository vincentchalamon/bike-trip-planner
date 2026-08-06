"use client";

import { useEffect, useRef } from "react";
import { useTranslations } from "next-intl";
import { Loader2, MapPin, MapPinned, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useUiStore } from "@/store/ui-store";
import { InRideDisclaimer } from "@/components/chat/InRideDisclaimer";
import { QuestionChips } from "@/components/in-ride/QuestionChips";
import { RecapMessage } from "@/components/in-ride/RecapMessage";
import { useInRideSearch } from "@/hooks/use-in-ride-search";
import { cn } from "@/lib/utils";

interface InRidePanelProps {
  /**
   * Called when the panel is dismissed (close button, Escape key). The parent
   * owns the global open flag so the bubble and panel stay in sync.
   */
  onClose: () => void;
}

const TITLE_ID = "in-ride-panel-title";

/**
 * Guided in-ride panel (#935) — replaces the free-text AI chat.
 *
 * Renders a 400 x 500 anchored panel on desktop, a full-screen sheet on mobile.
 * No composer: the rider taps a question chip, the search runs, and the thread
 * fills with a recap + POI cards. Every string comes from `next-intl`; nothing
 * server-authored is displayed. Escape closes the panel; focus lands on the
 * first chip at open and returns to the bubble on close (handled by the parent).
 */
export function InRidePanel({ onClose }: InRidePanelProps) {
  const t = useTranslations("chat.inRide");

  const thread = useUiStore((s) => s.chatHistory);
  const {
    isSearching,
    errorKey,
    geolocPromptVisible,
    canWiden,
    search,
    widen,
    requestGeoloc,
  } = useInRideSearch();

  const threadRef = useRef<HTMLDivElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);

  // Auto-scroll to the newest turn.
  useEffect(() => {
    const el = threadRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [thread.length, isSearching]);

  // Focus the first question chip on open so the panel is immediately usable
  // with the keyboard.
  useEffect(() => {
    const firstChip = panelRef.current?.querySelector<HTMLButtonElement>(
      '[data-testid^="in-ride-chip-"]',
    );
    firstChip?.focus();
  }, []);

  // Escape closes the panel.
  useEffect(() => {
    function handleKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", handleKey);
    return () => document.removeEventListener("keydown", handleKey);
  }, [onClose]);

  // The last recap owns the "widen" affordance.
  const lastRecapTs = [...thread].reverse().find((m) => m.kind === "recap")?.ts;

  return (
    <div
      ref={panelRef}
      role="dialog"
      aria-modal="false"
      aria-labelledby={TITLE_ID}
      id="in-ride-panel"
      data-testid="in-ride-panel"
      className={cn(
        "fixed z-40 flex flex-col bg-background shadow-2xl",
        "inset-0 md:inset-auto md:bottom-24 md:right-6",
        "md:w-[400px] md:h-[500px] md:rounded-2xl border md:border-border",
      )}
    >
      <header className="flex items-center gap-2 border-b border-border px-4 py-3">
        <MapPinned className="h-4 w-4 text-brand" aria-hidden="true" />
        <div className="min-w-0 flex-1">
          <h2 id={TITLE_ID} className="text-sm font-semibold text-foreground">
            {t("title")}
          </h2>
          <p className="truncate text-xs text-muted-foreground">
            {t("subtitle")}
          </p>
        </div>
        <Button
          type="button"
          size="icon"
          variant="ghost"
          onClick={onClose}
          aria-label={t("closeAria")}
          data-testid="in-ride-panel-close"
          className="h-8 w-8 shrink-0"
        >
          <X className="h-4 w-4" aria-hidden="true" />
        </Button>
      </header>

      <div
        ref={threadRef}
        role="log"
        aria-live="polite"
        aria-label={t("threadAria")}
        className="flex flex-1 flex-col gap-3 overflow-y-auto bg-muted/20 px-4 py-3"
      >
        {thread.map((message, index) =>
          message.kind === "recap" ? (
            <div
              key={`${message.role}-${message.ts}-${index}`}
              data-testid="in-ride-message"
              data-role={message.role}
              className="flex w-full justify-start"
            >
              <RecapMessage
                message={message}
                showWiden={canWiden && message.ts === lastRecapTs}
                onWiden={widen}
              />
            </div>
          ) : (
            <div
              key={`${message.role}-${message.ts}-${index}`}
              data-testid="in-ride-message"
              data-role={message.role}
              className="flex w-full justify-end"
            >
              <p className="max-w-[85%] self-end rounded-2xl rounded-br-sm bg-brand-fill px-3 py-2 text-sm leading-relaxed text-white shadow-sm">
                {message.category ? t(`question.${message.category}`) : ""}
              </p>
            </div>
          ),
        )}

        {isSearching && (
          <div
            role="status"
            aria-live="polite"
            className="flex items-center gap-2 self-start text-xs text-muted-foreground"
          >
            <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
            <span>{t("searching")}</span>
          </div>
        )}

        {errorKey && (
          <p
            role="alert"
            className="self-start rounded-lg border border-amber-300 bg-amber-50/60 px-3 py-2 text-xs text-amber-800"
          >
            {t(errorKey)}
          </p>
        )}
      </div>

      {geolocPromptVisible && (
        <button
          type="button"
          onClick={requestGeoloc}
          data-testid="in-ride-geoloc-prompt"
          className="flex w-full items-center gap-2 border-t border-border bg-muted/30 px-4 py-2 text-left text-xs text-muted-foreground underline-offset-2 hover:underline"
        >
          <MapPin className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          {t("geolocPrompt")}
        </button>
      )}

      <div className="border-t border-border p-3">
        <QuestionChips onSelect={search} disabled={isSearching} />
      </div>

      <div className="border-t border-border px-3 pb-3 pt-2">
        <InRideDisclaimer />
      </div>
    </div>
  );
}
