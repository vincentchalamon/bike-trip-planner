"use client";

import { useTranslations } from "next-intl";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

/**
 * Loading skeleton for {@link TimelineSidebar} (sprint 27, #402).
 *
 * Renders 4 dot markers with shimmer lines, mirroring the production sidebar
 * layout. The connecting brand-tinted line is preserved so the skeleton holds
 * the same vertical footprint as the real sidebar — no layout shift.
 */
export function TimelineSidebarSkeleton({
  count = 4,
  className,
}: {
  /** Number of placeholder rows to render (default 4). */
  count?: number;
  className?: string;
}) {
  const t = useTranslations("timeline");

  return (
    <nav
      aria-label={t("loadingStages")}
      aria-busy="true"
      data-testid="timeline-sidebar-skeleton"
      className={cn("relative", className)}
    >
      {/* Vertical connecting line — same offset as TimelineSidebar */}
      <div
        aria-hidden="true"
        className="absolute left-[15px] top-3 bottom-3 w-px bg-brand/30"
      />

      <ul className="flex flex-col">
        {Array.from({ length: count }).map((_, i) => (
          <li key={i} className="flex items-start gap-3 px-2 py-2">
            <span
              aria-hidden="true"
              className="relative z-10 mt-1 shrink-0 w-3 h-3 rounded-full border-[3px] border-brand/60 bg-background"
            />
            <span className="flex-1 min-w-0 flex flex-col gap-1">
              <Skeleton className="h-3.5 w-32" />
              <Skeleton className="h-3 w-20" />
            </span>
          </li>
        ))}
      </ul>
    </nav>
  );
}
