import { cn } from "@/lib/utils";

interface TimelineMarkerProps {
  className?: string;
}

export function TimelineMarker({ className }: TimelineMarkerProps) {
  return (
    <div
      className={cn(
        "relative z-10 w-4 h-4 rounded-full border-[3px] border-brand bg-background shrink-0",
        className,
      )}
      aria-hidden="true"
    />
  );
}
