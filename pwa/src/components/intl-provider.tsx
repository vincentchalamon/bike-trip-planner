"use client";

import type { ReactNode } from "react";
import {
  NextIntlClientProvider,
  type AbstractIntlMessages,
  type IntlError,
} from "next-intl";
import { logger } from "@/lib/logger";

/**
 * Client wrapper around `NextIntlClientProvider` that wires an `onError` handler
 * (audit 35.2 I18N-001). A missing/invalid translation key previously rendered
 * as a silent literal with no signal; now it throws in development (so drift is
 * caught immediately) and is reported via the structured logger in production.
 *
 * It lives in its own client component because `onError` is a function, which a
 * Server Component (the root layout) cannot pass as a prop to a client one.
 */
function handleIntlError(error: IntlError): void {
  if (process.env.NODE_ENV === "development") {
    throw error;
  }
  logger.warn("next-intl error", { error });
}

export function IntlProvider({
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
