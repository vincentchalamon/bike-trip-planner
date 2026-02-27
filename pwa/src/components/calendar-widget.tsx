"use client";

import {
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  ChevronUp,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { useCalendar } from "@/hooks/use-calendar";
import { useUiStore } from "@/store/ui-store";
import { cn } from "@/lib/utils";

interface CalendarWidgetProps {
  startDate: string | null;
  endDate: string | null;
  onDatesChange: (startDate: string | null, endDate: string | null) => void;
}

export function CalendarWidget({
  startDate,
  endDate,
  onDatesChange,
}: CalendarWidgetProps) {
  const expanded = useUiStore((s) => s.expandedCalendar);
  const setExpanded = useUiStore((s) => s.setExpandedCalendar);
  const {
    weeks,
    weekDayLabels,
    goToPreviousMonth,
    goToNextMonth,
    selectDate,
    monthLabel,
  } = useCalendar({ startDate, endDate, onDatesChange });

  const displayWeeks = expanded ? weeks : weeks.slice(0, 1);

  return (
    <div className="select-none">
      {/* Header */}
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2">
          {expanded && (
            <Button
              variant="ghost"
              size="icon"
              className="h-7 w-7"
              onClick={goToPreviousMonth}
              aria-label="Previous month"
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
          )}
          <span className="text-xl font-bold">{monthLabel}</span>
          {expanded && (
            <Button
              variant="ghost"
              size="icon"
              className="h-7 w-7"
              onClick={goToNextMonth}
              aria-label="Next month"
            >
              <ChevronRight className="h-4 w-4" />
            </Button>
          )}
        </div>
        <Button
          variant="ghost"
          size="icon"
          className="h-7 w-7"
          onClick={() => setExpanded(!expanded)}
          aria-label={expanded ? "Collapse calendar" : "Expand calendar"}
        >
          {expanded ? (
            <ChevronUp className="h-4 w-4" />
          ) : (
            <ChevronDown className="h-4 w-4" />
          )}
        </Button>
      </div>

      {/* Grid */}
      <div role="grid" aria-label="Calendar">
        {/* Day labels */}
        <div className="grid grid-cols-7 mb-1" role="row">
          {weekDayLabels.map((label) => (
            <div
              key={label}
              className="text-center text-xs font-bold text-muted-foreground py-1"
              role="columnheader"
            >
              {label}
            </div>
          ))}
        </div>

        {/* Weeks */}
        {displayWeeks.map((week, weekIndex) => (
          <div key={weekIndex} className="grid grid-cols-7" role="row">
            {week.map((day) => (
              <button
                key={day.date.format("YYYY-MM-DD")}
                role="gridcell"
                aria-selected={day.isSelected}
                onClick={() => selectDate(day.date)}
                className={cn(
                  "relative h-8 w-full text-sm rounded-md transition-colors",
                  "hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  !day.isCurrentMonth && "text-muted-foreground/40",
                  day.isToday && "font-bold",
                  day.isSelected &&
                    "bg-brand text-white ring-2 ring-white font-bold",
                  day.isInRange &&
                    "bg-brand/10 border border-dashed border-brand",
                )}
              >
                {day.date.date()}
              </button>
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}
