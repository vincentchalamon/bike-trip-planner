import { useState } from 'react';
import { Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { Button, Card, ListRow, Screen, SegmentedControl, Sheet, type Segment } from '../../src/components/ui';
import { ChevronRight } from '../../src/components/ui/icons';
import { LocaleSwitcher } from '../../src/i18n/LocaleSwitcher';
import { type Locale } from '../../src/i18n';
import { useTheme } from '../../src/theme';
import { useThemePrefs, type ThemeMode } from '../../src/store/theme-prefs';
import { useAuth } from '../../src/auth/store';
import { initialsFromEmail } from '../../src/auth/initials';

// Small uppercase caption above a section Card (matches the 10-account maquette).
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

// Trailing value + chevron for a navigation row.
function RowRight({ value }: { value?: string }) {
  const theme = useTheme();
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}>
      {value ? (
        <Text
          style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sans, fontSize: 14 }}
        >
          {value}
        </Text>
      ) : null}
      <ChevronRight color={theme.colors.mutedIcon} size={18} />
    </View>
  );
}

function ProfileHeader({ email }: { email: string | null }) {
  const theme = useTheme();
  const { t } = useTranslation();
  return (
    <Card>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.md }}>
        <View
          style={{
            width: 52,
            height: 52,
            borderRadius: theme.radius.full,
            backgroundColor: theme.colors.brand,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Text style={{ color: theme.colors.primaryForeground, fontFamily: theme.fonts.sansSemibold, fontSize: 18 }}>
            {initialsFromEmail(email)}
          </Text>
        </View>
        <View style={{ flex: 1 }}>
          <Text
            numberOfLines={1}
            style={{ color: theme.colors.foreground, fontFamily: theme.fonts.sansSemibold, fontSize: 16 }}
          >
            {email ?? ''}
          </Text>
          <Text
            style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sans, fontSize: 13, marginTop: 2 }}
          >
            {t('account.betaSubtitle')}
          </Text>
        </View>
      </View>
    </Card>
  );
}

function themeModeLabelKey(mode: ThemeMode) {
  if (mode === 'light') return 'account.themeLight' as const;
  if (mode === 'dark') return 'account.themeDark' as const;
  return 'account.themeSystem' as const;
}

export default function Account() {
  const { t, i18n } = useTranslation();
  const theme = useTheme();
  const router = useRouter();
  const { logout, email } = useAuth();
  const mode = useThemePrefs((s) => s.mode);
  const setMode = useThemePrefs((s) => s.setMode);
  const [languageOpen, setLanguageOpen] = useState(false);
  const [themeOpen, setThemeOpen] = useState(false);

  const currentLocale: Locale = i18n.language === 'en' ? 'en' : 'fr';
  // Active notification-category count. The notif store lands in #1120; until then
  // there is nothing enabled, so the counter reads 0.
  const notificationCount = 0;

  const themeSegments: readonly Segment<ThemeMode>[] = (['system', 'light', 'dark'] as const).map((m) => ({
    value: m,
    label: t(themeModeLabelKey(m)),
  }));

  return (
    <Screen scroll>
      <ProfileHeader email={email} />

      <SectionLabel>{t('account.sectionAccount')}</SectionLabel>
      <Card style={CARD_ROWS}>
        <ListRow
          title={t('account.email')}
          right={<RowRight />}
          onPress={() => router.push('/account/email')}
        />
      </Card>

      <SectionLabel>{t('account.sectionPreferences')}</SectionLabel>
      <Card style={CARD_ROWS}>
        <ListRow
          title={t('account.notifications')}
          right={<RowRight value={String(notificationCount)} />}
          onPress={() => router.push('/account/notifications')}
        />
        <ListRow
          title={t('account.language')}
          right={<RowRight value={t(`language.${currentLocale}`)} />}
          onPress={() => setLanguageOpen(true)}
        />
        <ListRow
          title={t('account.theme')}
          right={<RowRight value={t(themeModeLabelKey(mode))} />}
          onPress={() => setThemeOpen(true)}
        />
      </Card>

      <SectionLabel>{t('account.sectionData')}</SectionLabel>
      <Card style={CARD_ROWS}>
        <ListRow
          title={t('account.exportData')}
          right={<RowRight value={t('account.exportDataValue')} />}
          onPress={() => router.push('/account/export')}
        />
        <ListRow
          title={t('account.deleteAccount')}
          danger
          right={<RowRight />}
          onPress={() => router.push('/account/delete')}
        />
      </Card>

      <SectionLabel>{t('account.sectionHelp')}</SectionLabel>
      <Card style={CARD_ROWS}>
        <ListRow title={t('account.faq')} right={<RowRight />} onPress={() => router.push('/account/faq')} />
        <ListRow title={t('account.legal')} right={<RowRight />} onPress={() => router.push('/account/legal')} />
        <ListRow
          title={t('account.privacy')}
          right={<RowRight />}
          onPress={() => router.push('/account/privacy')}
        />
      </Card>

      <View style={{ height: theme.spacing.xl }} />
      <Button label={t('account.logout')} variant="outline" onPress={() => void logout()} />

      <Sheet visible={languageOpen} onClose={() => setLanguageOpen(false)} title={t('account.languageSheetTitle')}>
        <LocaleSwitcher />
      </Sheet>

      <Sheet visible={themeOpen} onClose={() => setThemeOpen(false)} title={t('account.themeSheetTitle')}>
        <SegmentedControl segments={themeSegments} value={mode} onChange={setMode} />
      </Sheet>
    </Screen>
  );
}

// Section Cards hold full-bleed ListRows: drop the Card's own padding so the row
// dividers reach the edges, matching the maquette.
const CARD_ROWS = { padding: 0 } as const;
