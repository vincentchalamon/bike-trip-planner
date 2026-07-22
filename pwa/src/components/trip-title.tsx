"use client";

import { useTranslations } from "next-intl";
import { EditableField } from "@/components/editable-field";
import { Skeleton } from "@/components/ui/skeleton";

interface TripTitleProps {
  title: string;
  onChange: (title: string) => void;
  isLoading?: boolean;
}

export function TripTitle({ title, onChange, isLoading }: TripTitleProps) {
  const t = useTranslations("tripTitle");

  if (isLoading) {
    return (
      <div>
        <Skeleton className="h-8 w-64" data-testid="trip-title-skeleton" />
      </div>
    );
  }

  return (
    <div>
      <EditableField
        value={title}
        onChange={onChange}
        className="text-xl md:text-2xl font-semibold"
        placeholder={t("placeholder")}
        // Include the visible title in the accessible name so it is not hidden
        // behind the field label (WCAG 2.5.3 Label in Name, A11Y-002).
        aria-label={[t("ariaLabel"), title].filter(Boolean).join(" : ")}
        data-testid="trip-title"
      />
    </div>
  );
}
