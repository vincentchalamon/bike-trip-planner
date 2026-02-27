"use client";

import { EditableField } from "@/components/editable-field";

const FEMINIST_NAMES = [
  "Annie Londonderry",
  "Alfonsina Strada",
  "Evelyne Carrer",
  "Beryl Burton",
  "Eileen Sheridan",
  "Marianne Martin",
  "Dervla Murphy",
  "Reine Bestel",
];

export function getRandomTripName(): string {
  return (
    FEMINIST_NAMES[Math.floor(Math.random() * FEMINIST_NAMES.length)] ??
    "My Trip"
  );
}

interface TripTitleProps {
  title: string;
  onChange: (title: string) => void;
}

export function TripTitle({ title, onChange }: TripTitleProps) {
  return (
    <EditableField
      value={title}
      onChange={onChange}
      className="text-xl md:text-2xl font-semibold"
      placeholder="Trip name"
      aria-label="Trip title"
      data-testid="trip-title"
    />
  );
}
