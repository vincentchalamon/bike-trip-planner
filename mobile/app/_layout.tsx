import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useTranslation } from 'react-i18next';
import '../src/i18n';
import { AuthProvider } from '../src/auth/store';
import { ThemeProvider, useTheme } from '../src/theme';

function RootNavigator() {
  const { t } = useTranslation();
  const theme = useTheme();
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
