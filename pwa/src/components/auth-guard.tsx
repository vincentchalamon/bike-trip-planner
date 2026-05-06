"use client";

import { useEffect, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { useAuthStore } from "@/store/auth-store";

/**
 * Paths that do not require authentication.
 * "/" is matched exactly; other entries use startsWith so nested routes are also public.
 */
const PUBLIC_EXACT_PATHS = [
  "/",
  "/faq",
  "/legal",
  "/privacy",
  "/access-requests/verify",
];
const PUBLIC_PREFIX_PATHS = ["/login", "/auth/verify", "/s/"];

function isPublicPath(pathname: string): boolean {
  return (
    PUBLIC_EXACT_PATHS.includes(pathname) ||
    PUBLIC_PREFIX_PATHS.some((p) => pathname.startsWith(p))
  );
}

/**
 * Client-side authentication guard.
 *
 * Wraps the application content and ensures:
 * 1. On initial load, attempts a silent refresh to restore the session
 *    from the httpOnly refresh_token cookie.
 * 2. If the user is not authenticated and the current path is protected,
 *    redirects to `/login`.
 * 3. Public pages (`/`, `/faq`, `/legal`, `/privacy`, `/access-requests/verify`,
 *    `/login`, `/auth/verify/*`, `/s/*`) are always accessible without authentication.
 *
 * Renders a blank screen during the initial auth check to prevent
 * flashing protected content before the redirect.
 */
export function AuthGuard({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const { isAuthenticated, silentRefresh } = useAuthStore();
  const [authChecked, setAuthChecked] = useState(false);

  useEffect(() => {
    let cancelled = false;

    const checkAuth = async () => {
      if (isAuthenticated) {
        if (!cancelled) setAuthChecked(true);
        return;
      }

      // Attempt silent refresh using the httpOnly refresh_token cookie
      const refreshed = await silentRefresh();
      if (cancelled) return;

      if (!refreshed && !isPublicPath(pathname)) {
        router.replace("/login");
        return;
      }

      setAuthChecked(true);
    };

    checkAuth();

    return () => {
      cancelled = true;
    };
  }, [isAuthenticated, pathname, router, silentRefresh]);

  // Always render public pages immediately
  if (isPublicPath(pathname)) {
    return <>{children}</>;
  }

  // Show nothing while checking auth to prevent content flash
  if (!authChecked) {
    return null;
  }

  // Authenticated — render children
  if (isAuthenticated) {
    return <>{children}</>;
  }

  // Not authenticated — will redirect via the effect above
  return null;
}
