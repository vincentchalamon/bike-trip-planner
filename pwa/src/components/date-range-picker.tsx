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
}

interface CalendarDay {
  date: Dayjs;
  isCurrentMonth: boolean;
  isToday: boolean;
  isPast: boolean;
  isSelected: boolean;
  isInRange: boolean;
  isStart: boolean;
  isEnd: boolean;
}

function buildMonthWeeks(
  month: Dayjs,
  start: Dayjs | null,
  end: Dayjs | null,
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

      week.push({
        date: current,
        isCurrentMonth: current.month() === month.month(),
        isToday: current.isSame(today, "day"),
        isPast: current.isBefore(today, "day"),
        isSelected: isStart || isEnd,
        isInRange,
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
  onSelectDate,
}: {
  month: Dayjs;
  weekDayLabels: string[];
  start: Dayjs | null;
  end: Dayjs | null;
  onSelectDate: (date: Dayjs) => void;
}) {
  const locale = useLocale();
  const weeks = useMemo(
    () => buildMonthWeeks(month, start, end),
    [month, start, end],
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
        {weeks.map((week, weekIndex) => (
          <div key={weekIndex} className="grid grid-cols-7" role="row">
            {week.map((day) => (
              <button
                key={day.date.format("YYYY-MM-DD")}
                role="gridcell"
                aria-selected={day.isSelected}
                aria-disabled={day.isPast}
                disabled={day.isPast}
                onClick={() => onSelectDate(day.date)}
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
  );
}

export function DateRangePicker({
  startDate,
  endDate,
  onDatesChange,
}: DateRangePickerProps) {
  const t = useTranslations("calendar");
  const locale = useLocale();
  const [open, setOpen] = useState(false);
  const [selectingEnd, setSelectingEnd] = useState(false);

  const start = startDate ? dayjs(startDate) : null;
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
        // First click: set start date, wait for end
        onDatesChange(dateStr, null);
        setSelectingEnd(true);
      } else {
        if (date.isBefore(start, "day")) {
          // Clicked before start: reset start
          onDatesChange(dateStr, null);
          setSelectingEnd(true);
        } else if (date.isSame(start, "day")) {
          // Clicked same day: clear selection
          onDatesChange(null, null);
          setSelectingEnd(false);
        } else {
          // Second click after start: set end date, auto-close
          onDatesChange(startDate, dateStr);
          setSelectingEnd(false);
          setOpen(false);
        }
      }
    },
    [selectingEnd, start, startDate, onDatesChange],
  );

  const goToPreviousMonth = useCallback(() => {
    setCurrentMonth((m) => m.subtract(1, "month"));
  }, []);

  const goToNextMonth = useCallback(() => {
    setCurrentMonth((m) => m.add(1, "month"));
  }, []);

  const formatDisplayDate = (date: string | null) => {
    if (!date) return "—";
    return dayjs(date).locale(locale).format("D MMM YYYY");
  };

  return (
    <Popover open={open} onOpenChange={handleOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          className={cn(
            "flex items-center gap-2 w-full rounded-md border px-3 py-2 text-sm",
            "hover:bg-accent transition-colors cursor-pointer text-left",
          )}
          data-testid="date-range-trigger"
        >
          <CalendarDays className="h-4 w-4 text-brand shrink-0" />
          <div className="flex flex-col gap-0.5 min-w-0">
            <span className="text-xs text-muted-foreground">
              {t("startDate")}
            </span>
            <span className="truncate">{formatDisplayDate(startDate)}</span>
          </div>
          <span className="text-muted-foreground mx-1">→</span>
          <div className="flex flex-col gap-0.5 min-w-0">
            <span className="text-xs text-muted-foreground">
              {t("endDate")}
            </span>
            <span className="truncate">{formatDisplayDate(endDate)}</span>
          </div>
        </button>
      </PopoverTrigger>
      <PopoverContent
        className="w-auto p-4 max-w-[95vw]"
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
            aria-label={t("previousMonth")}
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
            aria-label={t("nextMonth")}
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
          onSelectDate={handleSelectDate}
        />
      </PopoverContent>
    </Popover>
  );
}
