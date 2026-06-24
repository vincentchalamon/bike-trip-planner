"use client";

import {
  ErrorBoundaryContent,
  type ErrorBoundaryProps,
} from "@/components/error-boundary-content";

/**
 * Error boundary for the public pages (FAQ, legal, privacy). Unlike the root
 * `error.tsx`, this one renders *inside* `(public)/layout.tsx`, which already
 * provides the public chrome via {@link SiteChrome} (a segment layout survives
 * when its error boundary fires). So it must NOT wrap again — it only swaps the
 * page content for the error card, keeping the public header (recette #649).
 */
export default function PublicErrorBoundary(props: ErrorBoundaryProps) {
  return <ErrorBoundaryContent {...props} />;
}
