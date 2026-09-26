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
 * ⚠ It is NOT the provider the tree reads from first, and that is deliberate.
 * The root layout renders `NextIntlClientProvider` straight from the package,
 * and this one nests inside it with the same locale and messages. Reached only
 * through an app chunk, a provider is not there yet on the first render of a
 * route `next dev` compiles on demand: consumers resolve next-intl's own module,
 * find no context, and every page failed its first request (#1318). Rendering
 * the package's provider outermost means a consumer always finds one.
 *
 * So: do not "simplify" this by making it the only provider again, and do not
 * drop it either — `onError` is what turns a missing key into a signal instead
 * of silent text, and `i18n:check` cannot see that (it compares catalogues to
 * each other, never code to catalogue).
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
