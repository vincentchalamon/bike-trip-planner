import * as Linking from 'expo-linking';
import { useRouter } from 'expo-router';
import { useEffect } from 'react';
import { useAuth } from './store';

// Extracts a magic-link token from either form:
//   custom scheme : biketripplanner://verify?token=<token>
//   App Link      : https://<host>/auth/verify/<token>
export function extractToken(url: string): string | null {
  const parsed = Linking.parse(url);
  const q = parsed.queryParams?.token;
  if (typeof q === 'string' && q.length > 0) {
    return q;
  }
  const path = parsed.path ?? '';
  const match = path.match(/(?:^|\/)(?:auth\/)?verify\/([^/?#]+)/);
  return match ? decodeURIComponent(match[1]) : null;
}

// Listens for incoming deep links (cold start + warm) and completes the magic-link
// exchange, then routes to the authenticated tabs on success.
export function useDeepLinkAuth(): void {
  const url = Linking.useURL();
  const { verify } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (!url) {
      return;
    }
    const token = extractToken(url);
    if (!token) {
      return;
    }
    void (async () => {
      const ok = await verify(token);
      if (ok) {
        router.replace('/(tabs)');
      }
    })();
  }, [url, verify, router]);
}
