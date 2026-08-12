import { Redirect, Tabs } from 'expo-router';
import { useAuth } from '../../src/auth/store';

export default function TabsLayout() {
  const { ready, authenticated } = useAuth();

  if (ready && !authenticated) {
    return <Redirect href="/login" />;
  }

  return (
    <Tabs>
      <Tabs.Screen name="index" options={{ title: 'Voyages' }} />
      <Tabs.Screen name="map" options={{ title: 'Carte' }} />
    </Tabs>
  );
}
