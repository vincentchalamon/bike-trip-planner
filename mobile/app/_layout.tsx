import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useTranslation } from 'react-i18next';
import '../src/i18n';
import { AuthProvider } from '../src/auth/store';
import { usePushRouting } from '../src/notifications/use-push-routing';
import { useBackgroundTripSync } from '../src/hooks/use-background-sync';
import { useConnectivity } from '../src/store/use-connectivity';
import { ThemeProvider, useTheme } from '../src/theme';
import { useSystemNavigationBar } from '../src/theme/use-navigation-bar';

function RootNavigator() {
  const { t } = useTranslation();
  const theme = useTheme();
  // Route notification taps (warm + cold start) to the concerned screen (#1125).
  usePushRouting();
  // Feed real NetInfo connectivity into the offline store / mutation gate (#1146).
  useConnectivity();
  // Keep the offline trip cache fresh on return-to-online / app foreground (#1147).
  useBackgroundTripSync();
  // Harmonize the Android system navigation bar with the active theme (#1222).
  useSystemNavigationBar(theme.scheme);
  return (
    <Stack
      // The header integrates with the theme (light/dark) instead of the default
      // black-on-white; the trip / stage screens set their own dynamic title +
      // edit affordance via <Stack.Screen options> from within the screen.
      screenOptions={{
        headerStyle: { backgroundColor: theme.colors.background },
        headerTintColor: theme.colors.foreground,
        headerTitleStyle: { color: theme.colors.foreground },
        headerShadowVisible: false,
        contentStyle: { backgroundColor: theme.colors.background },
      }}
    >
      <Stack.Screen name="index" options={{ headerShown: false }} />
      <Stack.Screen name="login" options={{ title: t('header.login') }} />
      <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
      <Stack.Screen name="trip/[id]/index" />
      <Stack.Screen name="trip/[id]/stage/[index]" />
      {/* Anonymous shared-trip consultation, opened via the /s/<code> App Link. */}
      <Stack.Screen name="s/[code]" />
      <Stack.Screen name="auth/verify/[token]" options={{ headerShown: false }} />
    </Stack>
  );
}

export default function RootLayout() {
  return (
    <ThemeProvider>
      <AuthProvider>
        <StatusBar style="auto" />
        <RootNavigator />
      </AuthProvider>
    </ThemeProvider>
  );
}
