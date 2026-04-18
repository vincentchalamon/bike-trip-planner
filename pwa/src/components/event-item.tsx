"use client";

import { ExternalLink } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import type { EventData } from "@/lib/validation/schemas";

const EVENT_TYPE_KEYS: Record<string, string> = {
  "schema:Festival": "type_festival",
  "schema:Exhibition": "type_exhibition",
  "schema:MusicEvent": "type_music_event",
  "urn:resource:FairOrShow": "type_fair_or_show",
  market: "type_market",
};

function formatDateRange(
  startDate: string,
  endDate: string,
  locale: string,
): string {
  const start = new Date(startDate);
  const end = new Date(endDate);

  const fmt = new Intl.DateTimeFormat(locale, {
    day: "numeric",
    month: "short",
  });

  const startStr = fmt.format(start);
  const endStr = fmt.format(end);

  return startStr === endStr ? startStr : `${startStr} – ${endStr}`;
}

interface EventItemProps {
  event: EventData;
}

export function EventItem({ event }: EventItemProps) {
  const locale = useLocale();
  const t = useTranslations("events");
  const typeKey = EVENT_TYPE_KEYS[event.type];
  const typeLabel = typeKey ? t(typeKey) : event.type;
  const dateRange = formatDateRange(event.startDate, event.endDate, locale);

  return (
    <div className="py-2 first:pt-0 last:pb-0">
      <div className="flex items-start justify-between gap-2">
        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium leading-tight truncate">
            {event.name}
          </p>
          <div className="flex items-center gap-2 mt-0.5 flex-wrap">
            <span className="text-xs text-muted-foreground">{dateRange}</span>
            <span className="text-xs text-muted-foreground">·</span>
            <span className="text-xs text-muted-foreground">{typeLabel}</span>
            {event.priceMin !== null && event.priceMin !== undefined && (
              <>
                <span className="text-xs text-muted-foreground">·</span>
                <span className="text-xs text-muted-foreground">
                  {t("from_price", { price: event.priceMin })}
                </span>
              </>
            )}
          </div>
          {event.description && (
            <p className="text-xs text-muted-foreground mt-1 line-clamp-2">
              {event.description}
            </p>
          )}
          {event.openingHours && (
            <p className="text-xs text-muted-foreground mt-0.5">
              {event.openingHours}
            </p>
          )}
          {event.wikipediaUrl && (
            <a
              href={event.wikipediaUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-0.5 flex items-center gap-0.5 text-xs text-primary hover:underline"
              aria-label={t("see_on_wikipedia_label", { name: event.name })}
            >
              <ExternalLink className="h-3 w-3" />
              {t("see_on_wikipedia")}
            </a>
          )}
        </div>
        <div className="flex flex-col items-end gap-1 shrink-0">
          {event.imageUrl && (
            <img
              src={event.imageUrl}
              alt={event.name}
              loading="lazy"
              className="rounded aspect-[3/2] object-cover w-16"
            />
          )}
          {event.url && (
            <a
              href={event.url}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-1 text-xs text-primary hover:underline"
              aria-label={t("see_website_label", { name: event.name })}
            >
              <ExternalLink className="h-3 w-3" />
              <span className="hidden sm:inline">{t("see_website")}</span>
            </a>
          )}
        </div>
      </div>
    </div>
  );
}
