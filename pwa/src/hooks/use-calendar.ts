"use client";

import { useState, useCallback, useMemo } from "react";
import dayjs, { type Dayjs } from "dayjs";

export interface CalendarDay {
  date: Dayjs;
  isCurrentMonth: boolean;
  isToday: boolean;
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
  const start = startDate ? dayjs(startDate) : null;
  const end = endDate ? dayjs(endDate) : null;

  const [currentMonth, setCurrentMonth] = useState(() =>
    start ? start.startOf("month") : dayjs().startOf("month"),
  );

  const [selectingEnd, setSelectingEnd] = useState(false);

  const weekDayLabels = useMemo(
    () => ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"],
    [],
  );

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
      const dateStr = date.format("YYYY-MM-DD");
      if (!selectingEnd || !start) {
        onDatesChange(dateStr, endDate);
        setSelectingEnd(true);
      } else {
        if (date.isBefore(start)) {
          onDatesChange(dateStr, startDate);
        } else {
          onDatesChange(startDate, dateStr);
        }
        setSelectingEnd(false);
      }
    },
    [selectingEnd, start, startDate, endDate, onDatesChange],
  );

  const monthLabel = currentMonth.format("MMMM YYYY");

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
