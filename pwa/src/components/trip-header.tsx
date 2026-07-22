"use client";

import { TripTitle } from "@/components/trip-title";

interface TripHeaderProps {
  title: string;
  onTitleChange: (title: string) => void;
  isTitleLoading?: boolean;
}

export function TripHeader({
  title,
  onTitleChange,
  isTitleLoading,
}: TripHeaderProps) {
  return (
    <TripTitle
      title={title}
      onChange={onTitleChange}
      isLoading={isTitleLoading}
    />
  );
}
