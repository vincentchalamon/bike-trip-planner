"use client";

import {
  CircleCheckIcon,
  InfoIcon,
  Loader2Icon,
  OctagonXIcon,
  TriangleAlertIcon,
} from "lucide-react";
import { useTheme } from "next-themes";
import {
  toast as sonnerToast,
  Toaster as Sonner,
  type ExternalToast,
  type ToasterProps,
} from "sonner";
import { getLastRequestId } from "@/lib/api/client";

/**
 * Augments error/warning toasts with the last known correlation ID
 * (`X-Request-Id`). The ID is exposed both as a `data-request-id` attribute on
 * the rendered toast element (selectable by tests / power users via DevTools)
 * and as a `Request ID: <uuid>` description line so support requests can
 * carry the trace identifier. See issue #485.
 *
 * The original `sonner.toast.error` / `sonner.toast.warning` functions are
 * wrapped in place once at module load, so every caller importing `toast`
 * from `"sonner"` automatically benefits from the enrichment without a
 * codebase-wide refactor.
 */
type ToastMessage = Parameters<typeof sonnerToast.error>[0];
type ToastFn = (message: ToastMessage, data?: ExternalToast) => string | number;

function decorate(data: ExternalToast | undefined): ExternalToast | undefined {
  const requestId = getLastRequestId();
  if (requestId === null || requestId === "") {
    return data;
  }
  const existing = data ?? {};
  const description = existing.description ?? `Request ID: ${requestId}`;
  return {
    ...existing,
    description,
    // Cast: sonner spreads unknown props onto the rendered <li>, so `data-*`
    // keys land on the DOM element where they can be queried by tests and
    // copied by users via DevTools.
    ...({ "data-request-id": requestId } as Record<string, unknown>),
  };
}

const sonnerWithCorrelation = sonnerToast as typeof sonnerToast & {
  __correlationIdInstalled?: boolean;
};
if (!sonnerWithCorrelation.__correlationIdInstalled) {
  const originalError = sonnerToast.error.bind(sonnerToast) as ToastFn;
  const originalWarning = sonnerToast.warning.bind(sonnerToast) as ToastFn;
  sonnerToast.error = ((message: ToastMessage, data?: ExternalToast) =>
    originalError(message, decorate(data))) as typeof sonnerToast.error;
  sonnerToast.warning = ((message: ToastMessage, data?: ExternalToast) =>
    originalWarning(message, decorate(data))) as typeof sonnerToast.warning;
  sonnerWithCorrelation.__correlationIdInstalled = true;
}

const Toaster = ({ ...props }: ToasterProps) => {
  const { theme = "system" } = useTheme();

  return (
    <Sonner
      theme={theme as ToasterProps["theme"]}
      className="toaster group"
      icons={{
        success: <CircleCheckIcon className="size-4" />,
        info: <InfoIcon className="size-4" />,
        warning: <TriangleAlertIcon className="size-4" />,
        error: <OctagonXIcon className="size-4" />,
        loading: <Loader2Icon className="size-4 animate-spin" />,
      }}
      style={
        {
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
          "--border-radius": "var(--radius)",
        } as React.CSSProperties
      }
      {...props}
    />
  );
};

export { Toaster };
