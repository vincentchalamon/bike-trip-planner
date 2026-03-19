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
    <div className="flex-1 min-w-0">
      <div className="text-center text-sm font-semibold mb-2">
        {month.locale(locale).format("MMMM YYYY")}
      </div>
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

  // Draft state for the popover (committed on "Select")
  const [draftStart, setDraftStart] = useState<string | null>(null);
  const [draftEnd, setDraftEnd] = useState<string | null>(null);
  const [selectingEnd, setSelectingEnd] = useState(false);

  const start = startDate ? dayjs(startDate) : null;
  const [leftMonth, setLeftMonth] = useState(() =>
    start ? start.startOf("month") : dayjs().startOf("month"),
  );
  const rightMonth = leftMonth.add(1, "month");

  const draftStartDayjs = draftStart ? dayjs(draftStart) : null;
  const draftEndDayjs = draftEnd ? dayjs(draftEnd) : null;

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
        // Initialize draft from current dates
        setDraftStart(startDate);
        setDraftEnd(endDate);
        setSelectingEnd(!!startDate && !endDate);
        if (startDate) {
          setLeftMonth(dayjs(startDate).startOf("month"));
        } else {
          setLeftMonth(dayjs().startOf("month"));
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

      if (!selectingEnd || !draftStartDayjs) {
        setDraftStart(dateStr);
        setDraftEnd(null);
        setSelectingEnd(true);
      } else {
        if (date.isBefore(draftStartDayjs, "day")) {
          setDraftStart(dateStr);
          setDraftEnd(null);
          setSelectingEnd(true);
        } else if (date.isSame(draftStartDayjs, "day")) {
          setDraftStart(null);
          setDraftEnd(null);
          setSelectingEnd(false);
        } else {
          setDraftEnd(dateStr);
          setSelectingEnd(false);
        }
      }
    },
    [selectingEnd, draftStartDayjs],
  );

  const handleConfirm = useCallback(() => {
    onDatesChange(draftStart, draftEnd);
    setOpen(false);
  }, [draftStart, draftEnd, onDatesChange]);

  const handleCancel = useCallback(() => {
    setOpen(false);
  }, []);

  const goToPreviousMonth = useCallback(() => {
    setLeftMonth((m) => m.subtract(1, "month"));
  }, []);

  const goToNextMonth = useCallback(() => {
    setLeftMonth((m) => m.add(1, "month"));
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

        {/* Double-month grid (single month on mobile) */}
        <div className="flex gap-6">
          <MonthGrid
            month={leftMonth}
            weekDayLabels={weekDayLabels}
            start={draftStartDayjs}
            end={draftEndDayjs}
            onSelectDate={handleSelectDate}
          />
          <div className="hidden sm:block">
            <MonthGrid
              month={rightMonth}
              weekDayLabels={weekDayLabels}
              start={draftStartDayjs}
              end={draftEndDayjs}
              onSelectDate={handleSelectDate}
            />
          </div>
        </div>

        {/* Actions */}
        <div className="flex justify-end gap-2 mt-4 pt-3 border-t">
          <Button variant="outline" size="sm" onClick={handleCancel}>
            {t("cancel")}
          </Button>
          <Button size="sm" onClick={handleConfirm}>
            {t("selectRange")}
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  );
}
