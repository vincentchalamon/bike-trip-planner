import { cookies } from "next/headers";
import { HomeContent } from "@/components/home-content";

/**
 * Home page (dual-state: anonymous landing / authenticated dashboard).
 *
 * Server component so the anonymous landing is server-rendered with its `<main>`
 * and `<h1>` in the initial HTML (audit 35.2 A11Y-001/002 + LH-A11Y) — crawlable
 * and accessible, instead of the previous client-only render that emitted an
 * empty document. The presence of the httpOnly `refresh_token` cookie is passed
 * as a hint so authenticated users render the dashboard directly (no landing
 * flash) without a hydration mismatch; the client silent refresh then confirms.
 */
export default async function Page() {
  const cookieStore = await cookies();
  // Cookie name mirrors api `AuthCookies::REFRESH_TOKEN`.
  const authHint = cookieStore.get("refresh_token")?.value ? "authed" : "anon";

  return <HomeContent authHint={authHint} />;
}
