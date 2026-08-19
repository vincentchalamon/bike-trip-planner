import { useState } from 'react';
import { Alert, Modal, Pressable, Text, TextInput, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Check, Pencil, X } from '../ui/icons';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';
import { useTripMutations } from '../../hooks/use-trip-mutations';
import { nextTitle } from '../../screens/trip-actions';
import type { MutationFailure } from '../../store/gating';

// The navigation header's title for the roadbook and stage screens: the trip
// title plus an edit pencil (hidden when the trip is locked). Tapping it opens a
// small modal editor that renames the trip. Self-contained so both screens wire
// it as `headerTitle` without duplicating the edit state (#1105).
export function TripTitleHeader({ tripId }: { tripId: string }) {
  const { t } = useTranslation();
  const theme = useTheme();
  const title = useTripStore((s) => s.title);
  const isLocked = useTripStore((s) => s.isLocked);
  const resolvedTitle = title ?? t('trip.title');

  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState('');
  const onFailure = (reason: MutationFailure) =>
    Alert.alert(t('common.error'), t(`trip.edit.reason.${reason}`));
  const mutations = useTripMutations(tripId, onFailure);

  function startEdit() {
    setDraft(title ?? '');
    setEditing(true);
  }

  function save() {
    setEditing(false);
    const next = nextTitle(draft, title);
    if (next) void mutations.updateTitle(next);
  }

  return (
    <>
      <Pressable
        disabled={isLocked}
        accessibilityRole="button"
        accessibilityLabel={t('trip.editTitleA11y')}
        onPress={startEdit}
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          gap: theme.spacing.sm,
        }}
      >
        <Text
          numberOfLines={1}
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.serif,
            fontSize: 18,
            flexShrink: 1,
          }}
        >
          {resolvedTitle}
        </Text>
        {!isLocked ? <Pencil color={theme.colors.mutedIcon} size={16} /> : null}
      </Pressable>

      <Modal
        visible={editing}
        transparent
        animationType="fade"
        onRequestClose={() => setEditing(false)}
      >
        <Pressable
          onPress={() => setEditing(false)}
          style={{
            flex: 1,
            justifyContent: 'center',
            paddingHorizontal: theme.spacing.lg,
            // Same scrim literal as the shared Sheet backdrop.
            backgroundColor: 'rgba(0,0,0,0.4)',
          }}
        >
          <Pressable
            onPress={(e) => e.stopPropagation()}
            style={{
              backgroundColor: theme.colors.popover,
              borderRadius: theme.radius.lg,
              borderWidth: 1,
              borderColor: theme.colors.border,
              padding: theme.spacing.base,
              gap: theme.spacing.md,
              ...theme.shadows.strong,
            }}
          >
            <Text
              style={{
                color: theme.colors.foreground,
                fontFamily: theme.fonts.sansSemibold,
                fontSize: 15,
              }}
            >
              {t('trip.editTitleA11y')}
            </Text>
            <View
              style={{
                flexDirection: 'row',
                alignItems: 'center',
                gap: theme.spacing.sm,
              }}
            >
              <TextInput
                accessibilityLabel={t('trip.editTitleA11y')}
                value={draft}
                onChangeText={setDraft}
                autoFocus
                onSubmitEditing={save}
                placeholder={t('config.titlePlaceholder')}
                placeholderTextColor={theme.colors.mutedForeground}
                style={{
                  flex: 1,
                  height: 40,
                  borderWidth: 1,
                  borderColor: theme.colors.input,
                  borderRadius: theme.radius.md,
                  paddingHorizontal: theme.spacing.md,
                  color: theme.colors.foreground,
                  backgroundColor: theme.colors.surface,
                  fontFamily: theme.fonts.sansMedium,
                  fontSize: 16,
                }}
              />
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('trip.saveTitleA11y')}
                onPress={save}
                hitSlop={6}
                style={{ padding: theme.spacing.sm }}
              >
                <Check color={theme.colors.brandFill} size={22} />
              </Pressable>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('trip.edit.cancelA11y')}
                onPress={() => setEditing(false)}
                hitSlop={6}
                style={{ padding: theme.spacing.sm }}
              >
                <X color={theme.colors.mutedForeground} size={22} />
              </Pressable>
            </View>
          </Pressable>
        </Pressable>
      </Modal>
    </>
  );
}
