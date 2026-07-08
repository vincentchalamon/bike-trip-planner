import { Bike } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";

/**
 * Shared brand mark (#831).
 *
 * Single source of truth for the logo + wordmark so the header and footer no
 * longer drift (different icon/typography). The wordmark uses Fraunces
 * (`font-serif`) + `text-brand`, matching the hero and footer.
 *
 * `labelClassName` lets the caller hide the wordmark on narrow screens
 * (`hidden sm:inline`) while keeping the icon.
 */
export function Brand({
  className,
  iconClassName,
  labelClassName,
}: {
  className?: string;
  iconClassName?: string;
  labelClassName?: string;
}) {
  const t = useTranslations("navigation");

  return (
    <span
      className={cn(
        "inline-flex items-center gap-2 font-serif font-semibold text-brand",
        className,
      )}
    >
      <Bike
        className={cn("h-5 w-5 shrink-0", iconClassName)}
        aria-hidden="true"
      />
      <span className={labelClassName}>{t("brand")}</span>
    </span>
  );
}
