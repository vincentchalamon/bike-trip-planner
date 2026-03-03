import { AlertBadge } from "@/components/alert-badge";
import type { AlertData } from "@/lib/validation/schemas";

const severityOrder = { critical: 0, warning: 1, nudge: 2 } as const;

interface AlertListProps {
  alerts: AlertData[];
}

export function AlertList({ alerts }: AlertListProps) {
  if (alerts.length === 0) return null;

  const sorted = [...alerts].sort(
    (a, b) => (severityOrder[a.type] ?? 2) - (severityOrder[b.type] ?? 2),
  );

  return (
    <div className="flex flex-col gap-2">
      {sorted.map((alert, index) => (
        <AlertBadge
          key={`${alert.type}-${index}`}
          type={alert.type}
          message={alert.message}
        />
      ))}
    </div>
  );
}
