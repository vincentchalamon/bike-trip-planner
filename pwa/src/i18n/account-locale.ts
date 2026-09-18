import { cookies } from "next/headers";
import { backendFetch } from "@/lib/auth/backend";
import { isSupportedLocale, LOCALE_COOKIE } from "@/i18n/locale";
import { LOCALE_MAX_AGE_SECONDS } from "@/i18n/set-locale";

/**
 * Align the interface language with the account's stored locale, at login.
 *
 * Since ADR-063 the account's locale is what the server renders a trip's alerts
 * in. If the interface kept following the browser, a user whose account is `en`
 * would read a French interface next to English alerts — the mirror image of the
 * bug that ADR fixed. So the session begins by adopting the account's choice.
 *
 * Called from the BFF, which holds the fresh JWT: no client round-trip, and no
 * flash of the wrong language. Only at login — afterwards the switcher owns the
 * cookie, and pushes its changes back with `PATCH /users/me`.
 *
 * Never throws and never blocks the login: a user who cannot read their own
 * profile still gets a session, just in the language the browser already had.
 */
export async function syncLocaleFromAccount(
  accessToken: string,
): Promise<void> {
  try {
    const res = await backendFetch("/users/me", {
      headers: { Authorization: `Bearer ${accessToken}` },
    });
    if (!res.ok) return;

    const { locale } = (await res.json()) as { locale?: unknown };
    if (!isSupportedLocale(locale)) return;

    // Not httpOnly: the client-side switcher has to be able to rewrite it.
    (await cookies()).set(LOCALE_COOKIE, locale, {
      httpOnly: false,
      secure: true,
      sameSite: "lax",
      path: "/",
      maxAge: LOCALE_MAX_AGE_SECONDS,
    });
  } catch {
    // Offline, API down, malformed body: leave the browser's locale in place.
  }
}
