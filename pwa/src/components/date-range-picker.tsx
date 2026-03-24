"use client";

import { useState, useCallback, useMemo } from "react";
import { useTranslations, useLocale } from "next-intl";
import { CalendarDays, ChevronLeft, ChevronRight } from "lucide-react";
import dayjs, { type Dayjs } from "dayjs";
import "dayjs/locale/fr";
import "dayjs/locale/en";
import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { cn } from "@/lib/utils";

interface DateRangePickerProps {
  startDate: string | null;
  endDate: string | null;
  onDatesChange: (startDate: string | null, endDate: string | null) => void;
  /** When true, the date picker is read-only (all interaction is disabled). */
  disabled?: boolean;
}

interface CalendarDay {
  date: Dayjs;
  isCurrentMonth: boolean;
  isToday: boolean;
  isPast: boolean;
  isSelected: boolean;
  isInRange: boolean;
  isPreviewRange: boolean;
  isHovered: boolean;
  isStart: boolean;
  isEnd: boolean;
}

function buildMonthWeeks(
  month: Dayjs,
  start: Dayjs | null,
  end: Dayjs | null,
  hoveredDate: Dayjs | null,
  selectingEnd: boolean,
): CalendarDay[][] {
  const firstDay = month.startOf("month");
  const lastDay = month.endOf("month");
  const startOfGrid =
    firstDay.day() === 0
      ? firstDay.subtract(6, "day")
      : firstDay.subtract(firstDay.day() - 1, "day");

  const today = dayjs().startOf("day");
  const result: CalendarDay[][] = [];

  let current = startOfGrid;
  while (
    current.isBefore(lastDay) ||
    current.isSame(lastDay, "day") ||
    result.length < 6
  ) {
    const week: CalendarDay[] = [];
    for (let i = 0; i < 7; i++) {
      const isStart = start ? current.isSame(start, "day") : false;
      const isEnd = end ? current.isSame(end, "day") : false;
      const isInRange =
        start && end
          ? current.isAfter(start, "day") && current.isBefore(end, "day")
          : false;
      const isHovered = hoveredDate
        ? current.isSame(hoveredDate, "day")
        : false;
      // Preview range: when start is selected and hovering after it
      const isPreviewRange =
        selectingEnd &&
        !end &&
        start &&
        hoveredDate &&
        hoveredDate.isAfter(start, "day")
          ? current.isAfter(start, "day") &&
            (current.isBefore(hoveredDate, "day") ||
              current.isSame(hoveredDate, "day"))
          : false;

      week.push({
        date: current,
        isCurrentMonth: current.month() === month.month(),
        isToday: current.isSame(today, "day"),
        isPast: current.isBefore(today, "day"),
        isSelected: isStart || isEnd,
        isInRange,
        isPreviewRange,
        isHovered,
        isStart,
        isEnd,
      });
      current = current.add(1, "day");
    }
    result.push(week);
    if (result.length >= 6) break;
  }

  return result;
}

function MonthGrid({
  month,
  weekDayLabels,
  start,
  end,
  hoveredDate,
  selectingEnd,
  onSelectDate,
  onHoverDate,
  onLeave,
}: {
  month: Dayjs;
  weekDayLabels: string[];
  start: Dayjs | null;
  end: Dayjs | null;
  hoveredDate: Dayjs | null;
  selectingEnd: boolean;
  onSelectDate: (date: Dayjs) => void;
  onHoverDate: (date: Dayjs) => void;
  onLeave: () => void;
}) {
  const locale = useLocale();
  const weeks = useMemo(
    () => buildMonthWeeks(month, start, end, hoveredDate, selectingEnd),
    [month, start, end, hoveredDate, selectingEnd],
  );

  return (
    <div>
      <div role="grid" aria-label={month.locale(locale).format("MMMM YYYY")}>
        <div className="grid grid-cols-7 mb-1" role="row">
          {weekDayLabels.map((label) => (
            <div
              key={label}
              className="text-center text-xs font-bold text-muted-foreground py-0.5"
              role="columnheader"
            >
              {label}
            </div>
          ))}
        </div>
        <div onMouseLeave={onLeave}>
          {weeks.map((week, weekIndex) => (
            <div key={weekIndex} className="grid grid-cols-7" role="row">
              {week.map((day) => {
                const isEndCell =
                  day.isEnd ||
                  (day.isHovered && day.isPreviewRange && !day.isSelected);
                const inRange =
                  day.isInRange ||
                  (day.isPreviewRange && !day.isSelected && !isEndCell);
                const hasPreviewAfterStart =
                  selectingEnd &&
                  hoveredDate !== null &&
                  start !== null &&
                  hoveredDate.isAfter(start, "day");
                const isStartWithRange =
                  day.isStart &&
                  (day.isInRange || !!end || hasPreviewAfterStart);
                const isEndWithRange =
                  isEndCell && (day.isInRange || day.isPreviewRange || !!start);

                return (
                  <button
                    key={day.date.format("YYYY-MM-DD")}
                    role="gridcell"
                    aria-selected={day.isSelected}
                    aria-disabled={day.isPast}
                    disabled={day.isPast}
                    onClick={() => onSelectDate(day.date)}
                    onMouseEnter={() => !day.isPast && onHoverDate(day.date)}
                    className={cn(
                      "relative h-8 w-full text-sm transition-colors",
                      day.isPast
                        ? "text-muted-foreground/30 cursor-not-allowed"
                        : "cursor-pointer",
                      "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                      !day.isCurrentMonth &&
                        !day.isPast &&
                        "text-muted-foreground/40",
                      day.isToday && "font-bold",
                      // Range background (full cell width)
                      inRange && "bg-brand/10",
                      // Start date: range extends to the right half behind the circle
                      isStartWithRange &&
                        "bg-gradient-to-l from-brand/10 to-transparent",
                      // End date: range extends to the left half behind the circle
                      isEndWithRange &&
                        "bg-gradient-to-r from-brand/10 to-transparent",
                    )}
                  >
                    {/* Inner circle for selected/hovered dates */}
                    <span
                      className={cn(
                        "relative z-10 flex items-center justify-center h-full w-full rounded-full",
                        day.isSelected &&
                          "bg-brand text-white ring-2 ring-brand font-bold",
                        day.isHovered &&
                          !day.isSelected &&
                          !day.isPast &&
                          "ring-1 ring-foreground/40",
                      )}
                    >
                      {day.date.date()}
                    </span>
                  </button>
                );
              })}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

export function DateRangePicker({
  startDate,
  endDate,
  onDatesChange,
  disabled = false,
}: DateRangePickerProps) {
  const t = useTranslations();
  const locale = useLocale();
  const [open, setOpen] = useState(false);
  const [selectingEnd, setSelectingEnd] = useState(false);
  const [hoveredDate, setHoveredDate] = useState<Dayjs | null>(null);

  const start = useMemo(
    () => (startDate ? dayjs(startDate) : null),
    [startDate],
  );
  const [currentMonth, setCurrentMonth] = useState(() =>
    start ? start.startOf("month") : dayjs().startOf("month"),
  );

  const weekDayLabels = useMemo(() => {
    // Anchor to a known Monday so headers always match the Mon-first grid,
    // regardless of locale week-start convention.
    const monday = dayjs().day(1);
    return Array.from({ length: 7 }, (_, i) =>
      monday.add(i, "day").locale(locale).format("dd"),
    );
  }, [locale]);

  const handleOpen = useCallback(
    (isOpen: boolean) => {
      if (isOpen) {
        setSelectingEnd(!!startDate && !endDate);
        setHoveredDate(null);
        if (startDate) {
          setCurrentMonth(dayjs(startDate).startOf("month"));
        } else {
          setCurrentMonth(dayjs().startOf("month"));
        }
      }
      setOpen(isOpen);
    },
    [startDate, endDate],
  );

  const handleSelectDate = useCallback(
    (date: Dayjs) => {
      const today = dayjs().startOf("day");
      if (date.isBefore(today, "day")) return;

      const dateStr = date.format("YYYY-MM-DD");

      if (!selectingEnd || !start) {
        onDatesChange(dateStr, null);
        setSelectingEnd(true);
      } else {
        if (date.isBefore(start, "day")) {
          onDatesChange(dateStr, null);
          setSelectingEnd(true);
        } else if (date.isSame(start, "day")) {
          onDatesChange(null, null);
          setSelectingEnd(false);
        } else {
          onDatesChange(startDate, dateStr);
          setSelectingEnd(false);
          setOpen(false);
        }
      }
    },
    [selectingEnd, start, startDate, onDatesChange],
  );

  const handleHoverDate = useCallback((date: Dayjs) => {
    setHoveredDate(date);
  }, []);

  const handleLeaveGrid = useCallback(() => {
    setHoveredDate(null);
  }, []);

  const goToPreviousMonth = useCallback(() => {
    setCurrentMonth((m) => m.subtract(1, "month"));
  }, []);

  const goToNextMonth = useCallback(() => {
    setCurrentMonth((m) => m.add(1, "month"));
  }, []);

  const formatDisplayDate = (date: string | null, isStart: boolean) => {
    if (!date) return isStart && !endDate ? t("calendar.fromToday") : "—";
    return dayjs(date).locale(locale).format("D MMM YYYY");
  };

  return (
    <Popover open={disabled ? false : open} onOpenChange={handleOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          disabled={disabled}
          className={cn(
            "flex items-center gap-2 w-full rounded-md border px-3 py-2 text-sm",
            "hover:bg-accent transition-colors cursor-pointer text-left",
            disabled && "opacity-60 cursor-not-allowed pointer-events-none",
          )}
          data-testid="date-range-trigger"
        >
          <CalendarDays className="h-4 w-4 text-brand shrink-0" />
          <div className="flex flex-col gap-0.5 min-w-0">
            <span className="text-xs text-muted-foreground">
              {t("calendar.startDate")}
            </span>
            <span
              className={`truncate${!startDate ? " text-muted-foreground italic" : ""}`}
            >
              {formatDisplayDate(startDate, true)}
            </span>
          </div>
          <span className="text-muted-foreground mx-1">→</span>
          <div className="flex flex-col gap-0.5 min-w-0">
            <span className="text-xs text-muted-foreground">
              {t("calendar.endDate")}
            </span>
            <span className="truncate">
              {formatDisplayDate(endDate, false)}
            </span>
          </div>
        </button>
      </PopoverTrigger>
      <PopoverContent
        className="w-[var(--radix-popover-trigger-width)] p-4"
        align="start"
        sideOffset={8}
      >
        {/* Navigation */}
        <div className="flex items-center justify-between mb-3">
          <Button
            variant="ghost"
            size="icon"
            className="h-7 w-7"
            onClick={goToPreviousMonth}
            aria-label={t("calendar.previousMonth")}
          >
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <span className="text-sm font-semibold">
            {currentMonth.locale(locale).format("MMMM YYYY")}
          </span>
          <Button
            variant="ghost"
            size="icon"
            className="h-7 w-7"
            onClick={goToNextMonth}
            aria-label={t("calendar.nextMonth")}
          >
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>

        {/* Single month grid */}
        <MonthGrid
          month={currentMonth}
          weekDayLabels={weekDayLabels}
          start={start}
          end={endDate ? dayjs(endDate) : null}
          hoveredDate={hoveredDate}
          selectingEnd={selectingEnd}
          onSelectDate={handleSelectDate}
          onHoverDate={handleHoverDate}
          onLeave={handleLeaveGrid}
        />
      </PopoverContent>
    </Popover>
  );
}
