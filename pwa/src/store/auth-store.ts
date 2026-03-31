"use client";

import { create } from "zustand";
import { immer } from "zustand/middleware/immer";
import { API_URL } from "@/lib/constants";

interface AuthUser {
  id: string;
  email: string;
}

interface AuthState {
  accessToken: string | null;
  user: AuthUser | null;
  isAuthenticated: boolean;

  setAuth: (token: string, user: AuthUser) => void;
  requestMagicLink: (email: string) => Promise<void>;
  logout: () => Promise<void>;
  silentRefresh: () => Promise<boolean>;
  clearAuth: () => void;
}

/**
 * Parse a JWT payload without verifying the signature.
 * Used only to extract `sub` (user id) and `email` from the access token.
 *
 * JWTs use Base64url encoding (RFC 4648 §5): `-` instead of `+`, `_` instead
 * of `/`, and no padding. `atob()` requires standard Base64, so we normalise
 * before decoding.
 */
export function parseJwtPayload(
  token: string,
): { sub: string; email: string } | null {
  try {
    const parts = token.split(".");
    const encodedPayload = parts[1];
    if (parts.length !== 3 || !encodedPayload) return null;
    // Convert Base64url → standard Base64 and restore padding
    const base64 = encodedPayload.replace(/-/g, "+").replace(/_/g, "/");
    const padded = base64.padEnd(
      base64.length + ((4 - (base64.length % 4)) % 4),
      "=",
    );
    const payload = JSON.parse(atob(padded)) as Record<string, unknown>;
    // sub = UUID (from JwtCreatedListener), username = email (LexikJWTBundle default)
    const sub = typeof payload.sub === "string" ? payload.sub : null;
    const email =
      typeof payload.username === "string" ? payload.username : null;
    if (!sub || !email) {
      return null;
    }
    return { sub, email };
  } catch {
    return null;
  }
}

/**
 * Zustand store for authentication state.
 *
 * Manages the JWT access token, current user identity, and provides
 * actions for the passwordless magic-link authentication flow:
 *
 * - `requestMagicLink(email)` — asks the backend to send a magic link
 * - `silentRefresh()` — rotates the refresh token cookie and gets a new JWT
 * - `logout()` — revokes all refresh tokens and clears local state
 *
 * The access token is stored in-memory only (not persisted) for security.
 * The refresh token is managed as an httpOnly cookie by the backend.
 *
 * **Data flow:**
 * 1. User requests magic link → email sent with verification URL
 * 2. Backend verifies token → sets refresh_token cookie, issues JWT
 * 3. Frontend calls `silentRefresh()` → exchanges cookie for JWT
 * 4. JWT stored in this store → injected into API requests via middleware
 */
// Deduplicates concurrent silentRefresh calls: if a refresh is already in
// flight, all callers share the same promise rather than firing multiple
// /auth/refresh requests.
let pendingRefresh: Promise<boolean> | null = null;

export const useAuthStore = create<AuthState>()(
  immer((set, get) => ({
    accessToken: null,
    user: null,
    isAuthenticated: false,

    setAuth: (token, user) =>
      set((state) => {
        state.accessToken = token;
        state.user = user;
        state.isAuthenticated = true;
      }),

    requestMagicLink: async (email: string) => {
      await fetch(`${API_URL}/auth/request-link`, {
        method: "POST",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({ email }),
        credentials: "include",
      });
      // Always succeeds from the user's perspective (anti-enumeration)
    },

    logout: async () => {
      const { accessToken } = get();
      try {
        await fetch(`${API_URL}/auth/logout`, {
          method: "POST",
          headers: {
            ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
          },
          credentials: "include",
        });
      } catch {
        // Ignore network errors during logout — clear local state regardless
      }
      set((state) => {
        state.accessToken = null;
        state.user = null;
        state.isAuthenticated = false;
      });
    },

    silentRefresh: (): Promise<boolean> => {
      if (pendingRefresh) return pendingRefresh;

      pendingRefresh = (async () => {
        try {
          const res = await fetch(`${API_URL}/auth/refresh`, {
            method: "POST",
            credentials: "include",
          });

          if (!res.ok) {
            set((state) => {
              state.accessToken = null;
              state.user = null;
              state.isAuthenticated = false;
            });
            return false;
          }

          const data = (await res.json()) as { token: string };
          const payload = parseJwtPayload(data.token);

          if (!payload) {
            return false;
          }

          set((state) => {
            state.accessToken = data.token;
            state.user = { id: payload.sub, email: payload.email };
            state.isAuthenticated = true;
          });

          return true;
        } catch {
          set((state) => {
            state.accessToken = null;
            state.user = null;
            state.isAuthenticated = false;
          });
          return false;
        } finally {
          pendingRefresh = null;
        }
      })();

      return pendingRefresh;
    },

    clearAuth: () =>
      set((state) => {
        state.accessToken = null;
        state.user = null;
        state.isAuthenticated = false;
      }),
  })),
);
