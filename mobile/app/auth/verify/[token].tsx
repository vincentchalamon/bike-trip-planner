import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useRef } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../../../src/auth/store';

// Handles the magic-link verification deep link, for both forms:
//   App Link      : https://<host>/auth/verify/<token>
//   custom scheme : biketripplanner://auth/verify/<token>
// Expo Router maps both onto this file route (path /auth/verify/:token), so the
// token exchange runs here instead of racing the router from a Linking hook.
export default function VerifyScreen() {
  const { token } = useLocalSearchParams<{ token: string }>();
  const { verify } = useAuth();
  const router = useRouter();
  const handled = useRef(false);

  useEffect(() => {
    if (handled.current || typeof token !== 'string' || token.length === 0) {
      return;
    }
    handled.current = true;
    void (async () => {
      const ok = await verify(token);
      router.replace(ok ? '/(tabs)' : '/login');
    })();
  }, [token, verify, router]);

  return (
    <View style={styles.container}>
      <ActivityIndicator size="large" />
      <Text style={styles.label}>Connexion en cours…</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16 },
  label: { fontSize: 16 },
});
