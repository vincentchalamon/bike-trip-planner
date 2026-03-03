"use client";

import { useRef, useEffect, useState } from "react";
import { useTranslations } from "next-intl";
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
  const t = useTranslations("calendar");
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

  const contentRef = useRef<HTMLDivElement>(null);
  const [expandedHeight, setExpandedHeight] = useState<number>(0);
  const collapsedRowHeight = 32; // h-8 = 2rem = 32px

  useEffect(() => {
    if (contentRef.current && expanded) {
      setExpandedHeight(contentRef.current.scrollHeight);
    }
  }, [expanded, weeks]);

  return (
    <div className="select-none">
      {/* Header */}
      <div className="flex items-center mb-3">
        <Button
          variant="ghost"
          size="icon"
          className={cn("h-7 w-8 shrink-0", !expanded && "invisible")}
          onClick={goToPreviousMonth}
          aria-label={t("previousMonth")}
          tabIndex={expanded ? 0 : -1}
        >
          <ChevronLeft className="h-4 w-4" />
        </Button>

        <span className="text-xl font-bold text-center flex-1">
          {monthLabel}
        </span>

        <Button
          variant="ghost"
          size="icon"
          className={cn("h-7 w-8 shrink-0", !expanded && "invisible")}
          onClick={goToNextMonth}
          aria-label={t("nextMonth")}
          tabIndex={expanded ? 0 : -1}
        >
          <ChevronRight className="h-4 w-4" />
        </Button>

        <Button
          variant="ghost"
          size="icon"
          className="h-7 w-7 shrink-0 ml-1"
          onClick={() => setExpanded(!expanded)}
          aria-label={expanded ? t("collapse") : t("expand")}
        >
          {expanded ? (
            <ChevronUp className="h-4 w-4" />
          ) : (
            <ChevronDown className="h-4 w-4" />
          )}
        </Button>
      </div>

      {/* Grid */}
      <div role="grid" aria-label={t("ariaLabel")}>
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

        {/* Weeks with animation */}
        <div
          ref={contentRef}
          className="overflow-hidden transition-all duration-300 ease-in-out"
          style={{
            maxHeight: expanded
              ? `${expandedHeight || weeks.length * collapsedRowHeight}px`
              : `${collapsedRowHeight}px`,
          }}
        >
          {weeks.map((week, weekIndex) => (
            <div key={weekIndex} className="grid grid-cols-7" role="row">
              {week.map((day) => (
                <button
                  key={day.date.format("YYYY-MM-DD")}
                  role="gridcell"
                  aria-selected={day.isSelected}
                  aria-disabled={day.isPast}
                  disabled={day.isPast}
                  onClick={() => selectDate(day.date)}
                  className={cn(
                    "relative h-8 w-full text-sm rounded-md transition-colors",
                    day.isPast
                      ? "text-muted-foreground/30 cursor-not-allowed"
                      : "cursor-pointer hover:bg-accent",
                    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                    !day.isCurrentMonth &&
                      !day.isPast &&
                      "text-muted-foreground/40",
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
    </div>
  );
}
