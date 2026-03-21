"use server";

import { cookies } from "next/headers";
import type { SupportedLocale } from "./locale";

export async function setLocale(locale: SupportedLocale): Promise<void> {
  const store = await cookies();
  store.set("locale", locale, {
    path: "/",
    maxAge: 60 * 60 * 24 * 365, // 1 year
    sameSite: "lax",
    secure: true,
    httpOnly: true,
  });
}
