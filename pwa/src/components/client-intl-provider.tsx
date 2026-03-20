"use client";

import { useCallback, useEffect, useState, type ReactNode } from "react";
import { NextIntlClientProvider } from "next-intl";
import en from "../../messages/en.json";
import fr from "../../messages/fr.json";
import {
  DEFAULT_LOCALE,
  detectLocale,
  SUPPORTED_LOCALES,
  type SupportedLocale,
} from "@/i18n/locale";

const MESSAGES: Record<SupportedLocale, typeof fr> = { fr, en };
const LOCALE_STORAGE_KEY = "locale";

/**
 * Client-side i18n provider that detects and manages locale without
 * server-side cookies. Compatible with static export (Capacitor).
 *
 * Resolution order:
 * 1. localStorage (persisted user choice)
 * 2. navigator.language
 * 3. DEFAULT_LOCALE ("fr")
 */
export function ClientIntlProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<SupportedLocale>(DEFAULT_LOCALE);

  useEffect(() => {
    const stored = localStorage.getItem(LOCALE_STORAGE_KEY);
    if (stored && SUPPORTED_LOCALES.includes(stored as SupportedLocale)) {
      setLocaleState(stored as SupportedLocale);
    } else {
      setLocaleState(detectLocale());
    }
  }, []);

  const switchLocale = useCallback((next: SupportedLocale) => {
    setLocaleState(next);
    localStorage.setItem(LOCALE_STORAGE_KEY, next);
    document.documentElement.lang = next;
  }, []);

  return (
    <ClientIntlContext.Provider value={switchLocale}>
      <NextIntlClientProvider locale={locale} messages={MESSAGES[locale]}>
        {children}
      </NextIntlClientProvider>
    </ClientIntlContext.Provider>
  );
}

/* ── Context for locale switching ──────────────────────────────── */

import { createContext, useContext } from "react";

type SwitchLocaleFn = (locale: SupportedLocale) => void;

const ClientIntlContext = createContext<SwitchLocaleFn>(() => {});

export function useSwitchLocale(): SwitchLocaleFn {
  return useContext(ClientIntlContext);
}
