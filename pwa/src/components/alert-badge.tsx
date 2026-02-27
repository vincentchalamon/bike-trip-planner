import { cva } from "class-variance-authority";
import { AlertTriangle, AlertCircle, Info } from "lucide-react";
import { cn } from "@/lib/utils";

const alertVariants = cva(
  "rounded-md px-3 py-1.5 text-sm font-medium inline-flex items-center gap-1.5",
  {
    variants: {
      severity: {
        critical:
          "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
        warning:
          "bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400",
        nudge:
          "bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400",
      },
    },
    defaultVariants: {
      severity: "nudge",
    },
  },
);

const icons = {
  critical: AlertTriangle,
  warning: AlertCircle,
  nudge: Info,
} as const;

interface AlertBadgeProps {
  type: "critical" | "warning" | "nudge";
  message: string;
  className?: string;
}

export function AlertBadge({ type, message, className }: AlertBadgeProps) {
  const Icon = icons[type];

  return (
    <div
      className={cn(alertVariants({ severity: type }), className)}
      role={type === "critical" ? "alert" : undefined}
    >
      <Icon className="h-4 w-4 shrink-0" />
      <span>{message}</span>
    </div>
  );
}
