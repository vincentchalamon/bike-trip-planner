import { Redirect, Tabs } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { Plus, Route, Settings } from '../../src/components/ui/icons';
import { LoadingState } from '../../src/components/ui';
import { useTheme } from '../../src/theme';
import { useAuth } from '../../src/auth/store';

// Consolidated auth guard: the only place the app decides authenticated vs not.
// index.tsx redirects here, and deep links into the tab group pass through it.
export default function TabsLayout() {
  const { ready, authenticated } = useAuth();
  const { t } = useTranslation();
  const theme = useTheme();

  if (!ready) {
    return <LoadingState />;
  }
  if (!authenticated) {
    return <Redirect href="/login" />;
  }

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: theme.colors.brand,
        tabBarInactiveTintColor: theme.colors.mutedForeground,
        tabBarStyle: {
          backgroundColor: theme.colors.card,
          borderTopColor: theme.colors.border,
        },
      }}
    >
      <Tabs.Screen
        name="create"
        options={{
          title: t('nav.create'),
          tabBarIcon: ({ color, size }) => <Plus color={color} size={size} />,
        }}
      />
      <Tabs.Screen
        name="index"
        options={{
          title: t('nav.trips'),
          tabBarIcon: ({ color, size }) => <Route color={color} size={size} />,
        }}
      />
      <Tabs.Screen
        name="account"
        options={{
          title: t('nav.account'),
          tabBarIcon: ({ color, size }) => <Settings color={color} size={size} />,
        }}
      />
    </Tabs>
  );
}
