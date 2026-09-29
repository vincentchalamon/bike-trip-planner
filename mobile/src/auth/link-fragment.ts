import { useLinkingURL } from 'expo-linking';
import { useLocalSearchParams } from 'expo-router';

// The emailed verify links carry their token in the fragment
// (https://<host>/auth/verify#<token>) so it never reaches a server log.
export function fragmentOf(url: string | null): string {
  const index = url?.indexOf('#') ?? -1;
  return url && index >= 0 ? url.slice(index + 1) : '';
}

// Expo Router hands an App Link's fragment over as the `#` param but drops it
// from a custom-scheme link (biketripplanner://auth/verify#<token>), so the raw
// linking URL is the fallback.
export function useLinkFragment(): string {
  const { '#': fragment } = useLocalSearchParams<{ '#'?: string }>();
  const url = useLinkingURL();
  return typeof fragment === 'string' && fragment !== '' ? fragment : fragmentOf(url);
}
