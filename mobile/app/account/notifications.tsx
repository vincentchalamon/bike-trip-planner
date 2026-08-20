import { useEffect, useState } from 'react';
import { AppState, Text, View } from 'react-native';
import { Stack } from 'expo-router';
import { useTranslation } from 'react-i18next';
import * as Notifications from 'expo-notifications';
import { Button, Card, Screen, Switch } from '../../src/components/ui';
import {
  Bell,
  BellOff,
  Calendar,
  CheckCircle2,
  CloudSun,
  Download,
  MapPin,
} from '../../src/components/ui/icons';
import { useTheme } from '../../src/theme';
import { useNotificationPrefs } from '../../src/store/notification-prefs';

// Per-category metadata: icon + i18n keys resolving to `notifications.*` entries.
// `as const` keeps the keys as literals so `t()` accepts them.
const TRIP_CATEGORIES = [
  { category: 'weatherSafety', icon: CloudSun, titleKey: 'notifications.weatherSafetyTitle', descKey: 'notifications.weatherSafetyDesc' },
  { category: 'analysisDone', icon: CheckCircle2, titleKey: 'notifications.analysisDoneTitle', descKey: 'notifications.analysisDoneDesc' },
  { category: 'offlineNotReady', icon: Download, titleKey: 'notifications.offlineNotReadyTitle', descKey: 'notifications.offlineNotReadyDesc' },
  { category: 'tripNoDate', icon: Calendar, titleKey: 'notifications.tripNoDateTitle', descKey: 'notifications.tripNoDateDesc' },
] as const;

const COVERAGE_META = {
  category: 'zoneOpening',
  icon: MapPin,
  titleKey: 'notifications.zoneOpeningTitle',
  descKey: 'notifications.zoneOpeningDesc',
} as const;

type CategoryMeta = (typeof TRIP_CATEGORIES)[number] | typeof COVERAGE_META;

// Uppercase caption above a section Card (mirrors the account screen).
function SectionLabel({ children }: { children: string }) {
  const theme = useTheme();
  return (
    <Text
      style={{
        color: theme.colors.mutedForeground,
        fontFamily: theme.fonts.sansSemibold,
        fontSize: 12,
        letterSpacing: 0.4,
        textTransform: 'uppercase',
        marginBottom: theme.spacing.sm,
        marginTop: theme.spacing.lg,
      }}
    >
      {children}
    </Text>
  );
}

// Small pill flagging the opt-in category.
function OptInTag({ label }: { label: string }) {
  const theme = useTheme();
  return (
    <View
      style={{
        alignSelf: 'flex-start',
        marginTop: 4,
        paddingVertical: 2,
        paddingHorizontal: theme.spacing.sm,
        borderRadius: theme.radius.full,
        backgroundColor: theme.colors.muted,
        borderWidth: 1,
        borderColor: theme.colors.border,
      }}
    >
      <Text style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sansMedium, fontSize: 11 }}>
        {label}
      </Text>
    </View>
  );
}

// One category row: leading icon, title + description (+ optional opt-in tag),
// trailing switch. Not a ListRow because the description must wrap.
function CategoryRow({ meta, last, tag }: { meta: CategoryMeta; last?: boolean; tag?: string }) {
  const theme = useTheme();
  const { t } = useTranslation();
  const value = useNotificationPrefs((s) => s.enabled[meta.category]);
  const toggle = useNotificationPrefs((s) => s.toggle);
  const Icon = meta.icon;
  return (
    <View
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        gap: theme.spacing.md,
        paddingVertical: theme.spacing.md,
        paddingHorizontal: theme.spacing.base,
        borderBottomWidth: last ? 0 : 1,
        borderBottomColor: theme.colors.border,
      }}
    >
      <Icon color={theme.colors.mutedIcon} size={22} />
      <View style={{ flex: 1 }}>
        <Text style={{ color: theme.colors.foreground, fontFamily: theme.fonts.sansMedium, fontSize: 16 }}>
          {t(meta.titleKey)}
        </Text>
        <Text style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sans, fontSize: 13, marginTop: 2 }}>
          {t(meta.descKey)}
        </Text>
        {tag ? <OptInTag label={tag} /> : null}
      </View>
      <Switch value={value} onValueChange={() => toggle(meta.category)} label={t(meta.titleKey)} />
    </View>
  );
}

type PermissionState = 'unknown' | 'granted' | 'denied' | 'prompt';

// OS permission banner: reflects the system authorization and offers a request
// prompt when it can still be asked. Distinct from the per-category toggles,
// which are the app's own preference.
function PermissionBanner() {
  const theme = useTheme();
  const { t } = useTranslation();
  const [state, setState] = useState<PermissionState>('unknown');

  // Re-check on mount and every time the app returns to the foreground: the
  // common flow is toggling the OS permission in Android settings and coming
  // back, which must refresh the banner without a remount.
  useEffect(() => {
    let active = true;
    const check = () => {
      void Notifications.getPermissionsAsync().then((res) => {
        if (active) setState(res.granted ? 'granted' : res.canAskAgain ? 'prompt' : 'denied');
      });
    };
    check();
    const subscription = AppState.addEventListener('change', (next) => {
      if (next === 'active') check();
    });
    return () => {
      active = false;
      subscription.remove();
    };
  }, []);

  if (state === 'unknown') return null;

  const granted = state === 'granted';
  const Icon = granted ? CheckCircle2 : state === 'denied' ? BellOff : Bell;
  const tint = granted ? theme.colors.brand : theme.colors.mutedIcon;
  const title = t(
    granted
      ? 'notifications.permissionGranted'
      : state === 'denied'
        ? 'notifications.permissionDenied'
        : 'notifications.permissionPrompt',
  );
  const hint = t(
    granted
      ? 'notifications.permissionGrantedHint'
      : state === 'denied'
        ? 'notifications.permissionDeniedHint'
        : 'notifications.permissionPromptHint',
  );

  const request = () => {
    void Notifications.requestPermissionsAsync().then((res) => {
      setState(res.granted ? 'granted' : res.canAskAgain ? 'prompt' : 'denied');
    });
  };

  return (
    <Card>
      <View style={{ flexDirection: 'row', alignItems: 'flex-start', gap: theme.spacing.md }}>
        <Icon color={tint} size={22} />
        <View style={{ flex: 1 }}>
          <Text style={{ color: theme.colors.foreground, fontFamily: theme.fonts.sansSemibold, fontSize: 15 }}>
            {title}
          </Text>
          <Text style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sans, fontSize: 13, marginTop: 2 }}>
            {hint}
          </Text>
          {state === 'prompt' ? (
            <View style={{ marginTop: theme.spacing.md }}>
              <Button label={t('notifications.permissionAllow')} size="sm" onPress={request} />
            </View>
          ) : null}
        </View>
      </View>
    </Card>
  );
}

function Footer() {
  const theme = useTheme();
  const { t } = useTranslation();
  return (
    <Text
      style={{
        color: theme.colors.mutedForeground,
        fontFamily: theme.fonts.sans,
        fontSize: 12,
        lineHeight: 18,
        marginTop: theme.spacing.lg,
      }}
    >
      {t('notifications.footer')}
    </Text>
  );
}

export default function AccountNotifications() {
  const { t } = useTranslation();
  const load = useNotificationPrefs((s) => s.load);

  useEffect(() => void load(), [load]);

  return (
    <Screen scroll>
      <Stack.Screen options={{ headerShown: true, title: t('notifications.title') }} />

      <PermissionBanner />

      <SectionLabel>{t('notifications.groupTrips')}</SectionLabel>
      <Card style={CARD_ROWS}>
        {TRIP_CATEGORIES.map((meta, i) => (
          <CategoryRow key={meta.category} meta={meta} last={i === TRIP_CATEGORIES.length - 1} />
        ))}
      </Card>

      <SectionLabel>{t('notifications.groupCoverage')}</SectionLabel>
      <Card style={CARD_ROWS}>
        <CategoryRow meta={COVERAGE_META} last tag={t('notifications.optInTag')} />
      </Card>

      <Footer />
    </Screen>
  );
}

// Section Cards hold full-bleed rows: drop the Card's padding so dividers reach
// the edges (matches the account screen).
const CARD_ROWS = { padding: 0 } as const;
