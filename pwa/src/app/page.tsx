import { Suspense } from "react";
import { HomeContent } from "@/components/home-content";
import { resolveServerSession } from "@/lib/auth/server-session";

/**
 * Home page (dual-state: anonymous landing / authenticated dashboard).
 *
 * On the web the server resolves the REAL auth state (validated refresh-token
 * cookie, not a mere presence check) so a logged-in user is never shown the
 * landing and a stale/revoked cookie no longer flashes the dashboard shell
 * (ADR-047). `resolveServerSession()` returns `null` on the static
 * mobile/Capacitor build (`output: export`, no server) or a backend blip →
 * `initialAuthed = null` falls back to the client-side silent refresh.
 */
export default async function Page() {
  const session = await resolveServerSession();
  const initialAuthed = session?.authenticated ?? null;

  return (
    <Suspense fallback={null}>
      <HomeContent initialAuthed={initialAuthed} />
    </Suspense>
  );
}
