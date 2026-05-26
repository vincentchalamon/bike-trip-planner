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
 * (`X-Request-Id`).
 *
 * The ID is exposed in two reliable ways:
 *  - As a `Request ID: <uuid>` line appended to the toast description, so
 *    end-users can copy-paste it into a support request.
 *  - As the Sonner toast `id` (`toast-<uuid>`), which Sonner renders as the
 *    rendered `<li id>` DOM attribute, making the value selectable by
 *    Playwright tests and observable via DevTools. We deliberately do *not*
 *    spread arbitrary `data-*` keys on the toast options object: Sonner v2
 *    only forwards a fixed allow-list of known fields to the `<li>`, so a
 *    `data-request-id` entry would never reach the DOM.
 *
 * See issue #485.
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
  const description =
    existing.description != null && existing.description !== ""
      ? `${String(existing.description)}\nRequest ID: ${requestId}`
      : `Request ID: ${requestId}`;
  return {
    ...existing,
    description,
    // Sonner forwards `id` straight to the rendered `<li id>` DOM attribute,
    // so prefixing the request id keeps the value selectable from Playwright
    // / DevTools without relying on undocumented prop-spreading behaviour.
    id: existing.id ?? `toast-${requestId}`,
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
