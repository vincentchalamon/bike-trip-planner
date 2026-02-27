"use client";

import { TripTitle } from "@/components/trip-title";
import { LocationFields } from "@/components/location-fields";
import { WeatherIndicator } from "@/components/weather-indicator";
import { CalendarWidget } from "@/components/calendar-widget";
import type { StageData, WeatherData } from "@/lib/validation/schemas";
import type { GeocodeResult } from "@/lib/geocode/client";

interface TripHeaderProps {
  title: string;
  onTitleChange: (title: string) => void;
  departureLabel: string;
  arrivalLabel: string;
  isLoop: boolean;
  weather: WeatherData | null;
  startDate: string | null;
  endDate: string | null;
  onDatesChange: (startDate: string | null, endDate: string | null) => void;
  onDepartureChange: (result: GeocodeResult) => void;
  onArrivalChange: (result: GeocodeResult) => void;
}

export function TripHeader({
  title,
  onTitleChange,
  departureLabel,
  arrivalLabel,
  isLoop,
  weather,
  startDate,
  endDate,
  onDatesChange,
  onDepartureChange,
  onArrivalChange,
}: TripHeaderProps) {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-12">
      {/* Left column */}
      <div className="flex flex-col gap-3">
        <TripTitle title={title} onChange={onTitleChange} />
        <LocationFields
          departureLabel={departureLabel}
          arrivalLabel={arrivalLabel}
          isLoop={isLoop}
          onDepartureChange={onDepartureChange}
          onArrivalChange={onArrivalChange}
        />
        <WeatherIndicator weather={weather} />
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
