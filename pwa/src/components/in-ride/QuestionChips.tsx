"use client";

import { useTranslations } from "next-intl";
import {
  Cross,
  Droplet,
  ShoppingCart,
  Tent,
  TrainFront,
  UtensilsCrossed,
  Wrench,
  Zap,
  type LucideIcon,
} from "lucide-react";
import type { InRidePoiCategory } from "@/lib/api/client";
import { cn } from "@/lib/utils";

/**
 * The eight guided intents, in display order. Each drives one category chip
 * (label from `chat.inRide.question.<category>`) and maps to a backend
 * {@link InRidePoiCategory}.
 */
const CHIPS: ReadonlyArray<{ category: InRidePoiCategory; Icon: LucideIcon }> =
  [
    { category: "water", Icon: Droplet },
    { category: "shelter", Icon: Tent },
    { category: "food", Icon: UtensilsCrossed },
    { category: "resupply", Icon: ShoppingCart },
    { category: "mechanic", Icon: Wrench },
    { category: "health", Icon: Cross },
    { category: "train", Icon: TrainFront },
    { category: "charging", Icon: Zap },
  ];

interface QuestionChipsProps {
  onSelect: (category: InRidePoiCategory) => void;
  disabled?: boolean;
}

/**
 * The predefined-question chips that replace the free-text composer (#935).
 *
 * A rider with gloves under the rain taps one button; there is no typing. Each
 * chip is a real `<button>` inside a labelled `role="group"` so the whole set
 * is announced and reachable with the keyboard.
 */
export function QuestionChips({ onSelect, disabled }: QuestionChipsProps) {
  const t = useTranslations("chat.inRide");

  return (
    <div
      role="group"
      aria-label={t("chipsGroupAria")}
      data-testid="in-ride-chips"
      className="flex flex-wrap gap-2"
    >
      {CHIPS.map(({ category, Icon }) => (
        <button
          key={category}
          type="button"
          onClick={() => onSelect(category)}
          disabled={disabled}
          data-testid={`in-ride-chip-${category}`}
          className={cn(
            "inline-flex items-center gap-1.5 rounded-full border border-border bg-background",
            "px-3 py-1.5 text-xs font-medium text-foreground shadow-sm",
            "hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand",
            "disabled:cursor-not-allowed disabled:opacity-60",
          )}
        >
          <Icon className="h-3.5 w-3.5 text-brand" aria-hidden="true" />
          {t(`question.${category}`)}
        </button>
      ))}
    </div>
  );
}
