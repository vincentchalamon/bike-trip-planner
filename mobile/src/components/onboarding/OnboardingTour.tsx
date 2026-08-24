import { useEffect, useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { useTheme } from '../../theme/context';
import { Button } from '../ui';
import { Navigation, Plus, Route } from '../ui/icons';
import { useOnboarding } from '../../store/onboarding-prefs';

/**
 * OnboardingTour — a first-run guided tour (parity with the web driver.js tour).
 *
 * Shown once per install, gated on a SecureStore flag (`onboarding-prefs`):
 * three steps walking through the core mobile workflow — create a trip, follow
 * the roadbook, ride with the in-ride companion. Skippable at any point; both
 * Skip and finishing mark the flag so it never shows again. Mounted once, inside
 * the authenticated tab group.
 */
export function OnboardingTour() {
  const { t } = useTranslation();
  const theme = useTheme();
  const hydrated = useOnboarding((s) => s.hydrated);
  const seen = useOnboarding((s) => s.seen);
  const markSeen = useOnboarding((s) => s.markSeen);
  const load = useOnboarding((s) => s.load);
  const [step, setStep] = useState(0);

  useEffect(() => {
    void load();
  }, [load]);

  const steps = [
    { Icon: Plus, key: 'create' as const },
    { Icon: Route, key: 'roadbook' as const },
    { Icon: Navigation, key: 'ride' as const },
  ];

  // Wait for hydration (null = flag not read yet) to avoid a flash before we
  // know whether the tour was already seen.
  if (!hydrated || seen) return null;

  const isLast = step === steps.length - 1;
  const { Icon, key } = steps[step];

  return (
    <Modal visible transparent animationType="fade" onRequestClose={markSeen}>
      <View style={styles.backdrop} testID="onboarding-tour">
        <View
          style={[
            styles.card,
            {
              backgroundColor: theme.colors.card,
              borderRadius: theme.radius['3xl'],
              padding: theme.spacing.xl,
            },
          ]}
        >
          <View style={styles.header}>
            <Text style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sans, fontSize: 13 }}>
              {t('onboarding.progress', { current: step + 1, total: steps.length })}
            </Text>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('onboarding.skip')}
              hitSlop={12}
              onPress={markSeen}
            >
              <Text style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sansMedium, fontSize: 15 }}>
                {t('onboarding.skip')}
              </Text>
            </Pressable>
          </View>

          <View
            style={[
              styles.iconBadge,
              { backgroundColor: theme.colors.secondary, borderRadius: theme.radius.full },
            ]}
          >
            <Icon color={theme.colors.brand} size={28} />
          </View>

          <Text
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.serif,
              fontSize: 22,
              marginTop: theme.spacing.base,
            }}
          >
            {t(`onboarding.steps.${key}.title`)}
          </Text>
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.sans,
              fontSize: 15,
              lineHeight: 22,
              marginTop: theme.spacing.sm,
            }}
          >
            {t(`onboarding.steps.${key}.description`)}
          </Text>

          <View style={[styles.dots, { marginVertical: theme.spacing.base }]}>
            {steps.map((s, i) => (
              <View
                key={s.key}
                style={{
                  width: i === step ? 20 : 6,
                  height: 6,
                  borderRadius: 3,
                  backgroundColor: i === step ? theme.colors.brand : theme.colors.border,
                }}
              />
            ))}
          </View>

          <Button
            label={isLast ? t('onboarding.start') : t('onboarding.next')}
            fullWidth
            onPress={() => (isLast ? markSeen() : setStep((s) => s + 1))}
          />
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 24,
    backgroundColor: 'rgba(0,0,0,0.5)',
  },
  card: { width: '100%', maxWidth: 400 },
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  iconBadge: {
    width: 56,
    height: 56,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 12,
  },
  dots: { flexDirection: 'row', gap: 6, alignItems: 'center' },
});
