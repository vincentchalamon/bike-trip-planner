"use client";

import { useState } from "react";
import { CalendarDays, ChevronDown, ChevronUp } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { EventItem } from "@/components/event-item";
import type { EventData } from "@/lib/validation/schemas";

interface EventsPanelProps {
  events: EventData[];
}

export function EventsPanel({ events }: EventsPanelProps) {
  const [expanded, setExpanded] = useState(false);

  if (events.length === 0) {
    return null;
  }

  const sorted = [...events].sort(
    (a, b) => new Date(a.startDate).getTime() - new Date(b.startDate).getTime(),
  );

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
        <div
          className="mt-2 divide-y divide-border"
          data-testid="events-panel-content"
        >
          {sorted.map((event, i) => (
            <EventItem key={`${event.name}-${i}`} event={event} />
          ))}
        </div>
      )}
    </div>
  );
}
