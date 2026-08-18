import { Redirect, Stack } from 'expo-router';
import { useState } from 'react';
import {
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Defs, LinearGradient, Path, Rect, Stop } from 'react-native-svg';
import { useTranslation } from 'react-i18next';
import { Bike, KeyRound, Lock, Mail } from '../src/components/ui/icons';
import { Button, Screen } from '../src/components/ui';
import { useTheme } from '../src/theme';
import { useAuth } from '../src/auth/store';

const HERO_HEIGHT = 230;
const WAVE_HEIGHT = 60;

export default function Login() {
  const { t } = useTranslation();
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const { authenticated, requestLink } = useAuth();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (authenticated) {
    return <Redirect href="/(tabs)" />;
  }

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const ok = await requestLink(email.trim());
      if (ok) {
        setSent(true);
      } else {
        setError(t('login.error'));
      }
    } catch {
      setError(t('login.error'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen scroll padded={false} edges={['left', 'right']}>
      <Stack.Screen options={{ headerShown: false }} />

      <View style={{ height: HERO_HEIGHT }}>
        <Svg width={width} height={HERO_HEIGHT} style={StyleSheet.absoluteFill}>
          <Defs>
            <LinearGradient id="heroGrad" x1="0" y1="0" x2="1" y2="1">
              <Stop offset="0" stopColor={theme.colors.forest} />
              <Stop offset="1" stopColor={theme.colors.forestDeep} />
            </LinearGradient>
          </Defs>
          <Rect width={width} height={HERO_HEIGHT} fill="url(#heroGrad)" />
        </Svg>
        <Svg
          width={width}
          height={WAVE_HEIGHT}
          viewBox="0 0 393 60"
          preserveAspectRatio="none"
          style={styles.wave}
        >
          <Path
            d="M0,60 L0,30 Q100,0 196,25 T393,20 L393,60 Z"
            fill={theme.colors.background}
          />
        </Svg>
        <View style={[styles.brand, { top: insets.top + theme.spacing.xl }]}>
          <Bike color={theme.colors.heroForeground} size={40} />
          <Text
            style={{
              color: theme.colors.heroForeground,
              fontFamily: theme.fonts.serif,
              fontSize: 24,
              marginTop: theme.spacing.sm,
            }}
          >
            {t('login.brand')}
          </Text>
        </View>
      </View>

      <View style={{ padding: theme.spacing.xl, gap: theme.spacing.base }}>
        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.serif,
            fontSize: 22,
          }}
        >
          {t('login.title')}
        </Text>
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 14,
            lineHeight: 20,
          }}
        >
          {t('login.subtitle')}
        </Text>

        {sent ? (
          <View
            style={[
              styles.beta,
              {
                backgroundColor: theme.colors.brandLight,
                borderColor: theme.colors.border,
                borderRadius: theme.radius.xl,
                padding: theme.spacing.base,
              },
            ]}
          >
            <Mail color={theme.colors.accentInk} size={20} />
            <View style={styles.betaText}>
              <Text
                style={{
                  color: theme.colors.foreground,
                  fontFamily: theme.fonts.sansSemibold,
                  fontSize: 15,
                }}
              >
                {t('login.sentTitle')}
              </Text>
              <Text
                style={{
                  color: theme.colors.mutedForeground,
                  fontFamily: theme.fonts.sans,
                  fontSize: 13,
                  lineHeight: 18,
                }}
              >
                {t('login.sent', { email })}
              </Text>
            </View>
          </View>
        ) : (
          <>
            <View style={{ gap: theme.spacing.xs }}>
              <Text
                style={{
                  color: theme.colors.foreground,
                  fontFamily: theme.fonts.sansMedium,
                  fontSize: 14,
                }}
              >
                {t('login.emailLabel')}
              </Text>
              <View
                style={[
                  styles.field,
                  {
                    backgroundColor: theme.colors.surface,
                    borderColor: error ? theme.colors.destructive : theme.colors.input,
                    borderRadius: theme.radius.xl,
                    paddingHorizontal: theme.spacing.base,
                    gap: theme.spacing.md,
                  },
                ]}
              >
                <Mail color={theme.colors.mutedForeground} size={18} />
                <TextInput
                  value={email}
                  onChangeText={setEmail}
                  placeholder={t('login.emailPlaceholder')}
                  placeholderTextColor={theme.colors.mutedForeground}
                  autoCapitalize="none"
                  autoCorrect={false}
                  keyboardType="email-address"
                  style={{
                    flex: 1,
                    color: theme.colors.foreground,
                    fontFamily: theme.fonts.sans,
                    fontSize: 16,
                  }}
                />
              </View>
              {error ? (
                <Text
                  style={{
                    color: theme.colors.destructive,
                    fontFamily: theme.fonts.sans,
                    fontSize: 13,
                  }}
                >
                  {error}
                </Text>
              ) : null}
            </View>

            <Button
              label={busy ? t('login.submitting') : t('login.submit')}
              onPress={() => void submit()}
              loading={busy}
              disabled={busy || !email}
              fullWidth
              size="lg"
            />

            <View
              style={[
                styles.beta,
                {
                  backgroundColor: theme.colors.secondary,
                  borderColor: theme.colors.border,
                  borderRadius: theme.radius.lg,
                  padding: theme.spacing.md,
                },
              ]}
            >
              <Lock color={theme.colors.mutedForeground} size={18} />
              <View style={styles.betaText}>
                <Text
                  style={{
                    color: theme.colors.foreground,
                    fontFamily: theme.fonts.sansSemibold,
                    fontSize: 14,
                  }}
                >
                  {t('login.betaTitle')}
                </Text>
                <Text
                  style={{
                    color: theme.colors.mutedForeground,
                    fontFamily: theme.fonts.sans,
                    fontSize: 13,
                    lineHeight: 18,
                  }}
                >
                  {t('login.betaBody')}
                </Text>
              </View>
            </View>

            <View style={styles.note}>
              <KeyRound color={theme.colors.mutedForeground} size={14} />
              <Text
                style={{
                  color: theme.colors.mutedForeground,
                  fontFamily: theme.fonts.sans,
                  fontSize: 12,
                }}
              >
                {t('login.passwordless')}
              </Text>
            </View>
          </>
        )}
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  wave: { position: 'absolute', bottom: 0, left: 0 },
  brand: { position: 'absolute', left: 0, right: 0, alignItems: 'center' },
  field: {
    flexDirection: 'row',
    alignItems: 'center',
    height: 52,
    borderWidth: StyleSheet.hairlineWidth,
  },
  beta: { flexDirection: 'row', gap: 10, borderWidth: StyleSheet.hairlineWidth },
  betaText: { flex: 1, gap: 2 },
  note: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 },
});
