"use client";

import { useState, useCallback, useMemo } from "react";
import { useLocale } from "next-intl";
import dayjs, { type Dayjs } from "dayjs";
import "dayjs/locale/fr";
import "dayjs/locale/en";

export interface CalendarDay {
  date: Dayjs;
  isCurrentMonth: boolean;
  isToday: boolean;
  isPast: boolean;
  isSelected: boolean;
  isInRange: boolean;
  isStart: boolean;
  isEnd: boolean;
}

interface UseCalendarOptions {
  startDate: string | null;
  endDate: string | null;
  onDatesChange: (startDate: string | null, endDate: string | null) => void;
}

interface UseCalendarReturn {
  currentMonth: Dayjs;
  weeks: CalendarDay[][];
  weekDayLabels: string[];
  goToPreviousMonth: () => void;
  goToNextMonth: () => void;
  selectDate: (date: Dayjs) => void;
  monthLabel: string;
}

export function useCalendar({
  startDate,
  endDate,
  onDatesChange,
}: UseCalendarOptions): UseCalendarReturn {
  const locale = useLocale();
  const start = startDate ? dayjs(startDate) : null;
  const end = endDate ? dayjs(endDate) : null;

  const [currentMonth, setCurrentMonth] = useState(() =>
    start ? start.startOf("month") : dayjs().startOf("month"),
  );

  const [selectingEnd, setSelectingEnd] = useState(false);

  const weekDayLabels = useMemo(() => {
    const monday = dayjs().locale(locale).startOf("week");
    return Array.from({ length: 7 }, (_, i) =>
      monday.add(i, "day").format("dd"),
    );
  }, [locale]);

  const weeks = useMemo(() => {
    const firstDay = currentMonth.startOf("month");
    const lastDay = currentMonth.endOf("month");

    // Get Monday of the first week
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
          isCurrentMonth: current.month() === currentMonth.month(),
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
  }, [currentMonth, start, end]);

  const goToPreviousMonth = useCallback(() => {
    setCurrentMonth((m) => m.subtract(1, "month"));
  }, []);

  const goToNextMonth = useCallback(() => {
    setCurrentMonth((m) => m.add(1, "month"));
  }, []);

  const selectDate = useCallback(
    (date: Dayjs) => {
      const today = dayjs().startOf("day");
      if (date.isBefore(today, "day")) return;

      const dateStr = date.format("YYYY-MM-DD");

      if (!selectingEnd || !start) {
        // First click or no start: set as start date, clear end
        onDatesChange(dateStr, null);
        setSelectingEnd(true);
      } else {
        // Second click: set as end date
        if (date.isBefore(start, "day")) {
          // Clicked before start: treat as new start date, clear end
          onDatesChange(dateStr, null);
          setSelectingEnd(true);
        } else if (date.isSame(start, "day")) {
          // Clicked same day: clear selection
          onDatesChange(null, null);
          setSelectingEnd(false);
        } else {
          // Clicked after start: set as end date
          onDatesChange(startDate, dateStr);
          setSelectingEnd(false);
        }
      }
    },
    [selectingEnd, start, startDate, onDatesChange],
  );

  const monthLabel = currentMonth.locale(locale).format("MMMM YYYY");

  return {
    currentMonth,
    weeks,
    weekDayLabels,
    goToPreviousMonth,
    goToNextMonth,
    selectDate,
    monthLabel,
  };
}
