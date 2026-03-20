"use server";

import { cookies } from "next/headers";

export const SUPPORTED_LOCALES = ["fr", "en"] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

export async function setLocale(locale: SupportedLocale): Promise<void> {
  const store = await cookies();
  store.set("locale", locale, {
    path: "/",
    maxAge: 60 * 60 * 24 * 365, // 1 year
    sameSite: "lax",
  });
}

export async function getCurrentLocale(): Promise<SupportedLocale> {
  const store = await cookies();
  const value = store.get("locale")?.value;
  if (value && SUPPORTED_LOCALES.includes(value as SupportedLocale)) {
    return value as SupportedLocale;
  }
  return "fr";
}
