"use client";

import { ExternalLink } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import type { EventData } from "@btp/core";
import { normalizeExternalUrl } from "@/lib/validation/url";

const EVENT_TYPE_KEYS: Record<string, string> = {
  "schema:Festival": "type_festival",
  "schema:Exhibition": "type_exhibition",
  "schema:MusicEvent": "type_music_event",
  "urn:resource:FairOrShow": "type_fair_or_show",
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
  // DataTourisme is a less-trusted external source than our own backend, so gate
  // its links through the same http(s)-only normalizer the rest of the app uses
  // (alert-list, PoiCard) — React does not strip javascript:/data: hrefs.
  const wikipediaHref = normalizeExternalUrl(event.wikipediaUrl);
  const websiteHref = normalizeExternalUrl(event.url);

  return (
    <div className="py-2 first:pt-0 last:pb-0">
      <p className="text-sm font-medium leading-tight">{event.name}</p>
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
      {event.openingHours && (
        <p className="text-xs text-muted-foreground mt-0.5">
          {event.openingHours}
        </p>
      )}
      {(wikipediaHref || websiteHref) && (
        <div className="mt-0.5 flex items-center gap-3 flex-wrap">
          {wikipediaHref && (
            <a
              href={wikipediaHref}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-0.5 text-xs text-primary hover:underline"
              aria-label={t("see_on_wikipedia_label", { name: event.name })}
            >
              <ExternalLink className="h-3 w-3" />
              {t("see_on_wikipedia")}
            </a>
          )}
          {websiteHref && (
            <a
              href={websiteHref}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-0.5 text-xs text-primary hover:underline"
              aria-label={t("see_website_label", { name: event.name })}
            >
              <ExternalLink className="h-3 w-3" />
              {t("see_website")}
            </a>
          )}
        </div>
      )}
    </div>
  );
}
