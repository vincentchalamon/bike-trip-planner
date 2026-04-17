"use client";

import Link from "next/link";
import { useAuthStore } from "@/store/auth-store";
import { Button } from "@/components/ui/button";

export function CtaButton({
  label,
  size = "default",
  className = "",
}: {
  label: string;
  size?: "default" | "lg" | "sm" | "icon";
  className?: string;
}) {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const href = isAuthenticated ? "/trips/new" : "/login";

  return (
    <Button
      asChild
      size={size}
      className={`bg-brand hover:bg-brand-hover text-white font-semibold ${className}`}
    >
      <Link href={href} data-testid="cta-create-itinerary">
        {label}
      </Link>
    </Button>
  );
}
