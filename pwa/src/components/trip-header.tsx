"use client";

import { TripTitle } from "@/components/trip-title";
import { CalendarWidget } from "@/components/calendar-widget";

interface TripHeaderProps {
  title: string;
  onTitleChange: (title: string) => void;
  startDate: string | null;
  endDate: string | null;
  onDatesChange: (startDate: string | null, endDate: string | null) => void;
  showTitleSuggestion?: boolean;
  isTitleLoading?: boolean;
  children?: React.ReactNode;
}

export function TripHeader({
  title,
  onTitleChange,
  startDate,
  endDate,
  onDatesChange,
  showTitleSuggestion,
  isTitleLoading,
  children,
}: TripHeaderProps) {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-12">
      {/* Left column */}
      <div className="flex flex-col gap-3">
        <TripTitle
          title={title}
          onChange={onTitleChange}
          showSuggestion={showTitleSuggestion}
          isLoading={isTitleLoading}
        />
        {children}
      </div>

      {/* Right column */}
      <div>
        <CalendarWidget
          startDate={startDate}
          endDate={endDate}
          onDatesChange={onDatesChange}
        />
      </div>
    </div>
  );
}
