"use client";

import {
  createContext,
  useCallback,
  useContext,
  useSyncExternalStore,
  type ReactNode,
} from "react";
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

let currentLocale: SupportedLocale = DEFAULT_LOCALE;
const listeners = new Set<() => void>();

function getLocaleSnapshot(): SupportedLocale {
  return currentLocale;
}

function getServerSnapshot(): SupportedLocale {
  return DEFAULT_LOCALE;
}

function setLocale(next: SupportedLocale) {
  currentLocale = next;
  listeners.forEach((l) => l());
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

function initLocale() {
  if (typeof window === "undefined") return;
  const stored = localStorage.getItem(LOCALE_STORAGE_KEY);
  if (stored && SUPPORTED_LOCALES.includes(stored as SupportedLocale)) {
    currentLocale = stored as SupportedLocale;
  } else {
    currentLocale = detectLocale();
  }
  document.documentElement.lang = currentLocale;
}

initLocale();

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
  const locale = useSyncExternalStore(
    subscribe,
    getLocaleSnapshot,
    getServerSnapshot,
  );

  const switchLocale = useCallback((next: SupportedLocale) => {
    setLocale(next);
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

type SwitchLocaleFn = (locale: SupportedLocale) => void;

const ClientIntlContext = createContext<SwitchLocaleFn>(() => {});

export function useSwitchLocale(): SwitchLocaleFn {
  return useContext(ClientIntlContext);
}
