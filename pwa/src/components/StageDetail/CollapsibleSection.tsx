"use client";

import { useId, useState, useCallback, type ReactNode } from "react";
import { ChevronDown } from "lucide-react";

interface CollapsibleSectionProps {
  /** Trigger label (rendered inside the button). */
  title: ReactNode;
  /** Optional leading icon shown before the title. */
  icon?: ReactNode;
  /** Body content; only rendered while expanded to keep the DOM lean. */
  children: ReactNode;
  /** Initial expansion state. Defaults to collapsed. */
  defaultOpen?: boolean;
  /** Forwarded `data-testid` on the root element for E2E targeting. */
  testId?: string;
  /** Optional CSS class on the root element. */
  className?: string;
  /** Optional ARIA label override; defaults to the visible title. */
  ariaLabel?: string;
}

/**
 * Generic collapsible section used across the right-hand stage detail panel.
 *
 * - Single chevron that rotates 180° when expanded (design-system standard).
 * - Body is unmounted when collapsed — keeps the DOM small and avoids hidden
 *   focusable nodes interfering with keyboard navigation.
 * - Uses the standard `aria-expanded` / `aria-controls` pattern so screen
 *   readers announce the open/close state.
 */
export function CollapsibleSection({
  title,
  icon,
  children,
  defaultOpen = false,
  testId,
  className,
  ariaLabel,
}: CollapsibleSectionProps) {
  const [open, setOpen] = useState(defaultOpen);
  const contentId = useId();
  const toggle = useCallback(() => setOpen((prev) => !prev), []);

  return (
    <div className={className} data-testid={testId}>
      <button
        type="button"
        className="flex w-full items-center justify-between gap-2 py-1 text-sm font-medium text-foreground hover:text-foreground/80 transition-colors cursor-pointer"
        onClick={toggle}
        aria-expanded={open}
        aria-controls={contentId}
        aria-label={ariaLabel}
        data-testid={testId ? `${testId}-toggle` : undefined}
      >
        <span className="flex items-center gap-1.5 min-w-0">
          {icon}
          <span className="truncate">{title}</span>
        </span>
        <ChevronDown
          className={`h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200 ${
            open ? "rotate-180" : "rotate-0"
          }`}
          aria-hidden="true"
        />
      </button>
      {open && (
        <div
          id={contentId}
          className="mt-2"
          data-testid={testId ? `${testId}-body` : undefined}
        >
          {children}
        </div>
      )}
    </div>
  );
}
