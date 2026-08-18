import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useTranslation } from 'react-i18next';
import '../src/i18n';
import { AuthProvider } from '../src/auth/store';
import { ThemeProvider } from '../src/theme';

function RootNavigator() {
  const { t } = useTranslation();
  return (
    <Stack>
      <Stack.Screen name="index" options={{ headerShown: false }} />
      <Stack.Screen name="login" options={{ title: t('header.login') }} />
      <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
      <Stack.Screen name="trip/[id]/index" options={{ title: t('header.trip') }} />
      <Stack.Screen
        name="trip/[id]/stage/[index]"
        options={{ title: t('header.stageDetail') }}
      />
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
