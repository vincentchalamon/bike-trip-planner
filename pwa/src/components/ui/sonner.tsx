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
 * Rather than mutating `sonner.toast.error` / `sonner.toast.warning` in
 * place (fragile: ESM namespaces are frozen in strict mode, load-order
 * dependent, and silently breaks on upstream upgrades), we expose a
 * thin wrapper `toast` object that delegates to the upstream callable
 * while decorating the options bag. PWA callers import `toast` from this
 * module instead of `"sonner"`.
 */
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

const toast = Object.assign(
  (message: Parameters<typeof sonnerToast>[0], data?: ExternalToast) =>
    sonnerToast(message, data),
  sonnerToast,
  {
    error: (
      message: Parameters<typeof sonnerToast.error>[0],
      data?: ExternalToast,
    ) => sonnerToast.error(message, decorate(data)),
    warning: (
      message: Parameters<typeof sonnerToast.warning>[0],
      data?: ExternalToast,
    ) => sonnerToast.warning(message, decorate(data)),
  },
) as typeof sonnerToast;

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

export { Toaster, toast };
