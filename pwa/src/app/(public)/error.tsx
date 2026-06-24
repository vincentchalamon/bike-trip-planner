"use client";

import { SiteChrome } from "@/components/site-chrome";
import {
  ErrorBoundaryContent,
  type ErrorBoundaryProps,
} from "@/components/error-boundary-content";

/**
 * Error boundary for the public pages (FAQ, legal, privacy) — same card as the
 * root boundary but with the public header (`PublicTopBar`) so an error thrown
 * while browsing a public page does not flip to the authenticated chrome
 * (recette #649).
 */
export default function PublicErrorBoundary(props: ErrorBoundaryProps) {
  return (
    <SiteChrome variant="public">
      <ErrorBoundaryContent {...props} />
    </SiteChrome>
  );
}
