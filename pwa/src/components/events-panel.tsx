"use client";

import { useState } from "react";
import { CalendarDays, ChevronDown, ChevronUp } from "lucide-react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { EventItem } from "@/components/event-item";
import type { EventData } from "@btp/core";

const DEFAULT_VISIBLE = 3;

interface EventsPanelProps {
  events: EventData[];
}

export function EventsPanel({ events }: EventsPanelProps) {
  const t = useTranslations("events");
  const [expanded, setExpanded] = useState(false);
  const [showAll, setShowAll] = useState(false);

  if (events.length === 0) {
    return null;
  }

  const sorted = [...events].sort(
    (a, b) => new Date(a.startDate).getTime() - new Date(b.startDate).getTime(),
  );

  const visible = showAll ? sorted : sorted.slice(0, DEFAULT_VISIBLE);
  const hidden = sorted.length - visible.length;

  return (
    <div data-testid="events-panel">
      <Separator className="mt-4 mb-3" />
      <Button
        variant="ghost"
        className="w-full justify-between px-0 h-auto py-1 text-sm font-medium hover:bg-transparent"
        onClick={() => setExpanded((v) => !v)}
        aria-expanded={expanded}
        data-testid="events-panel-toggle"
      >
        <span className="flex items-center gap-1.5">
          <CalendarDays className="h-4 w-4 text-muted-foreground" />
          <span>{`Événements (${events.length})`}</span>
        </span>
        {expanded ? (
          <ChevronUp className="h-4 w-4 text-muted-foreground" />
        ) : (
          <ChevronDown className="h-4 w-4 text-muted-foreground" />
        )}
      </Button>

      {expanded && (
        <div className="mt-2 pl-[22px] pr-1" data-testid="events-panel-content">
          <div className="divide-y divide-border">
            {visible.map((event, i) => (
              <EventItem key={`${event.name}-${i}`} event={event} />
            ))}
          </div>
          {hidden > 0 && (
            <Button
              variant="ghost"
              size="sm"
              className="mt-1 h-auto px-0 py-1 text-xs text-primary hover:bg-transparent hover:underline"
              onClick={() => setShowAll(true)}
              data-testid="events-panel-show-more"
            >
              {t("show_more", { count: hidden })}
            </Button>
          )}
        </div>
      )}
    </div>
  );
}
