"use client";

import { TripTitle } from "@/components/trip-title";

interface TripHeaderProps {
  title: string;
  onTitleChange: (title: string) => void;
  showTitleSuggestion?: boolean;
  isTitleLoading?: boolean;
}

export function TripHeader({
  title,
  onTitleChange,
  showTitleSuggestion,
  isTitleLoading,
}: TripHeaderProps) {
  return (
    <TripTitle
      title={title}
      onChange={onTitleChange}
      showSuggestion={showTitleSuggestion}
      isLoading={isTitleLoading}
    />
  );
}
