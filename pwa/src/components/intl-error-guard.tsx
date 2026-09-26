"use client";

import type { ReactNode } from "react";
import {
  NextIntlClientProvider,
  type AbstractIntlMessages,
  type IntlError,
} from "next-intl";
import { logger } from "@/lib/logger";

/**
 * Wires next-intl's `onError` handler (audit 35.2 I18N-001) for the client tree.
 * A missing or invalid translation key would otherwise render as a silent
 * literal; it now throws in development (so drift surfaces on the spot) and is
 * reported through the structured logger in production.
 *
 * It has to be a client component, because `onError` is a function and a Server
 * Component cannot pass one as a prop.
 *
 * ⚠ It is NOT the provider the tree reads from, and that is the fix for #1318.
 * The root layout renders `NextIntlClientProvider` itself, straight from the
 * package, and this one nests inside it with the same locale and messages. When
 * this component WAS the only provider, the first render of a route after
 * `next dev` compiled it on demand found no context at all — the provider was
 * reached through an app chunk that was not ready yet, while the consumers
 * resolved next-intl's own. Every page answered its first request with `Failed
 * to call useTranslations because the context from NextIntlClientProvider was
 * not found`, and the public pages turned that into a 500 (the authenticated
 * ones absorbed it and answered 200, which is why it read as a route-group
 * problem and is not one).
 *
 * With the package's provider outermost, a consumer always finds a context: the
 * canonical one in that first render, this one from then on. Measured on a cold
 * dev server, first request per route: `/`, `/privacy`, `/faq` and `/trips/new`
 * go from "500, or 200 with the context error logged" to 200 with nothing
 * logged — and a deliberately missing key still surfaces as an error page rather
 * than silent text, so the guard below is still doing its job.
 */
function handleIntlError(error: IntlError): void {
  if (process.env.NODE_ENV === "development") {
    throw error;
  }
  logger.warn("next-intl error", { error });
}

export function IntlErrorGuard({
  locale,
  messages,
  children,
}: {
  locale: string;
  messages: AbstractIntlMessages;
  children: ReactNode;
}) {
  return (
    <NextIntlClientProvider
      locale={locale}
      messages={messages}
      onError={handleIntlError}
    >
      {children}
    </NextIntlClientProvider>
  );
}
