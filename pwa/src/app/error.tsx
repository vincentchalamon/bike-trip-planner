"use client";

import { SiteChrome } from "@/components/site-chrome";
import {
  ErrorBoundaryContent,
  type ErrorBoundaryProps,
} from "@/components/error-boundary-content";

/**
 * Root error boundary — catches errors thrown anywhere not covered by a nested
 * boundary. Renders with the authenticated app chrome. Public pages have their
 * own `(public)/error.tsx` so an error there keeps the public header.
 */
export default function ErrorBoundary(props: ErrorBoundaryProps) {
  return (
    <SiteChrome variant="app">
      <ErrorBoundaryContent {...props} />
    </SiteChrome>
  );
}
