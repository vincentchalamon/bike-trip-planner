import { Suspense } from "react";
import { HomeContent } from "@/components/home-content";

/**
 * Home page (dual-state: anonymous landing / authenticated dashboard).
 *
 * On the web (standalone) the server reads the refresh-token cookie so a
 * logged-in user is never shown the landing (the browser-only dashboard then
 * renders on mount), instead of flashing the landing while `silentRefresh`
 * runs. The static mobile/Capacitor build (`output: export`) cannot read
 * cookies — and a `cookies()` call would break that build — so the read is
 * guarded behind the build target; mobile falls back to the client-side silent
 * refresh (`initialAuthed = null`).
 */
export default async function Page() {
  let initialAuthed: boolean | null = null;

  if (process.env.NEXT_PUBLIC_IS_MOBILE_BUILD !== "1") {
    const { cookies } = await import("next/headers");
    initialAuthed = (await cookies()).has("refresh_token");
  }

  return (
    <Suspense fallback={null}>
      <HomeContent initialAuthed={initialAuthed} />
    </Suspense>
  );
}
