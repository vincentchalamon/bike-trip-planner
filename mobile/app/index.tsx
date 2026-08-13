import { Redirect } from 'expo-router';

// The single auth guard lives in app/(tabs)/_layout.tsx: it redirects to /login
// when unauthenticated. This entry route just points at the protected group.
export default function Index() {
  return <Redirect href="/(tabs)" />;
}
