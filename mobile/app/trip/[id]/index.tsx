import { useLocalSearchParams, useRouter } from 'expo-router';
import { type ReactNode, useState } from 'react';
import { Alert, Modal, Pressable, Text, TextInput, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import {
  ErrorState,
  LoadingState,
  Screen,
  SegmentedControl,
  type Segment,
} from '../../../src/components/ui';
import {
  Check,
  Copy,
  Download,
  MoreVertical,
  Pencil,
  Settings,
  Share2,
  Trash2,
  X,
} from '../../../src/components/ui/icons';
import {
  ConfigSheet,
  RoadbookView,
  ShareSheet,
  SseStatusIndicator,
  TripMapView,
} from '../../../src/components/trip';
import { useTheme } from '../../../src/theme';
import { useExport } from '../../../src/hooks/use-export';
import { confirmDeleteTrip } from '../../../src/hooks/use-trips';
import { useTripLive } from '../../../src/hooks/use-trip-live';
import { useTripMutations } from '../../../src/hooks/use-trip-mutations';
import { nextTitle } from '../../../src/screens/trip-actions';
import type { MutationFailure } from '../../../src/store/gating';
import { useTripStore } from '../../../src/store/trip-store';

type TripView = 'roadbook' | 'map';

// One row of the header kebab dropdown: an icon + label, with a `danger` variant
// for the destructive delete action.
function MenuItem({
  icon,
  label,
  onPress,
  danger = false,
}: {
  icon: ReactNode;
  label: string;
  onPress: () => void;
  danger?: boolean;
}) {
  const theme = useTheme();
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      onPress={onPress}
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        gap: theme.spacing.md,
        paddingVertical: theme.spacing.sm,
        paddingHorizontal: theme.spacing.md,
      }}
    >
      {icon}
      <Text
        style={{
          color: danger ? theme.colors.destructive : theme.colors.foreground,
          fontFamily: theme.fonts.sansMedium,
          fontSize: 15,
        }}
      >
        {label}
      </Text>
    </Pressable>
  );
}

export default function TripRoadbook() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useTranslation();
  const theme = useTheme();
  const router = useRouter();
  const [view, setView] = useState<TripView>('roadbook');
  const [configOpen, setConfigOpen] = useState(false);
  const [shareOpen, setShareOpen] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [editingTitle, setEditingTitle] = useState(false);
  const [titleDraft, setTitleDraft] = useState('');

  // Hydrate the shared store from /detail and keep it live via SSE. The child
  // views render straight from the store, so a stage_updated event reconciled by
  // the core reducers updates them in place (no ad-hoc local state).
  useTripLive(id);

  const title = useTripStore((s) => s.title);
  const computing = useTripStore((s) => s.computing);
  const loading = useTripStore((s) => s.loading);
  const error = useTripStore((s) => s.error);
  const isLocked = useTripStore((s) => s.isLocked);

  const onFailure = (reason: MutationFailure) =>
    Alert.alert(t('common.error'), t(`trip.edit.reason.${reason}`));
  const mutations = useTripMutations(id, onFailure);
  const { exportTrip } = useExport(() =>
    Alert.alert(t('export.failedTitle'), t('export.failedMessage')),
  );

  const resolvedTitle = title ?? t('trip.title');

  const segments: readonly Segment<TripView>[] = [
    { value: 'roadbook', label: t('trip.segmentRoadbook') },
    { value: 'map', label: t('trip.segmentMap') },
  ];

  function startEditTitle() {
    setTitleDraft(title ?? '');
    setEditingTitle(true);
  }

  function saveTitle() {
    setEditingTitle(false);
    const next = nextTitle(titleDraft, title);
    if (next) void mutations.updateTitle(next);
  }

  function onDeleteTrip() {
    confirmDeleteTrip({
      title: t('trip.deleteTripConfirmTitle'),
      message: t('trip.deleteTripConfirmMessage'),
      cancel: t('trip.cancel'),
      confirm: t('trip.menu.delete'),
      onConfirm: () =>
        void mutations.deleteTrip().then((ok) => {
          if (ok) router.back();
        }),
    });
  }

  function duplicate() {
    void mutations.duplicate().then((newId) => {
      if (newId) router.push(`/trip/${newId}`);
    });
  }

  if (loading) {
    return (
      <Screen padded={false}>
        <LoadingState />
      </Screen>
    );
  }

  if (error) {
    return (
      <Screen padded={false}>
        <ErrorState title={t('common.error')} description={error} />
      </Screen>
    );
  }

  return (
    <Screen padded={false}>
      <View style={{ padding: theme.spacing.base, gap: theme.spacing.md }}>
        <View
          style={{
            flexDirection: 'row',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: theme.spacing.sm,
          }}
        >
          {editingTitle ? (
            <>
              <TextInput
                accessibilityLabel={t('trip.editTitleA11y')}
                value={titleDraft}
                onChangeText={setTitleDraft}
                autoFocus
                onSubmitEditing={saveTitle}
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
                  fontSize: 18,
                }}
              />
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('trip.saveTitleA11y')}
                onPress={saveTitle}
                hitSlop={6}
                style={{ padding: theme.spacing.sm }}
              >
                <Check color={theme.colors.brandFill} size={22} />
              </Pressable>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('trip.edit.cancelA11y')}
                onPress={() => setEditingTitle(false)}
                hitSlop={6}
                style={{ padding: theme.spacing.sm }}
              >
                <X color={theme.colors.mutedForeground} size={22} />
              </Pressable>
            </>
          ) : (
            <>
              <Pressable
                disabled={isLocked}
                accessibilityRole="button"
                accessibilityLabel={t('trip.editTitleA11y')}
                onPress={startEditTitle}
                style={{
                  flex: 1,
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
                    fontSize: 24,
                    flexShrink: 1,
                  }}
                >
                  {resolvedTitle}
                </Text>
                {!isLocked ? (
                  <Pencil color={theme.colors.mutedIcon} size={16} />
                ) : null}
              </Pressable>
              <SseStatusIndicator computing={computing} />
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('trip.menu.open')}
                onPress={() => setMenuOpen(true)}
                hitSlop={8}
                style={{ padding: theme.spacing.xs }}
              >
                <MoreVertical color={theme.colors.foreground} size={22} />
              </Pressable>
            </>
          )}
        </View>
        <SegmentedControl segments={segments} value={view} onChange={setView} />
      </View>

      {view === 'map' ? <TripMapView /> : <RoadbookView id={id} />}

      <Modal
        visible={menuOpen}
        transparent
        animationType="fade"
        onRequestClose={() => setMenuOpen(false)}
      >
        <Pressable onPress={() => setMenuOpen(false)} style={{ flex: 1 }}>
          <View
            style={{
              position: 'absolute',
              top: theme.spacing['3xl'],
              right: theme.spacing.base,
              minWidth: 220,
              backgroundColor: theme.colors.popover,
              borderRadius: theme.radius.lg,
              borderWidth: 1,
              borderColor: theme.colors.border,
              paddingVertical: theme.spacing.xs,
              ...theme.shadows.strong,
            }}
          >
            <MenuItem
              icon={<Settings color={theme.colors.foreground} size={18} />}
              label={t('trip.menu.config')}
              onPress={() => {
                setMenuOpen(false);
                setConfigOpen(true);
              }}
            />
            <MenuItem
              icon={<Share2 color={theme.colors.foreground} size={18} />}
              label={t('trip.menu.share')}
              onPress={() => {
                setMenuOpen(false);
                setShareOpen(true);
              }}
            />
            <MenuItem
              icon={<Download color={theme.colors.foreground} size={18} />}
              label={t('trip.menu.exportGpx')}
              onPress={() => {
                setMenuOpen(false);
                void exportTrip(id, resolvedTitle, 'gpx');
              }}
            />
            <MenuItem
              icon={<Download color={theme.colors.foreground} size={18} />}
              label={t('trip.menu.exportFit')}
              onPress={() => {
                setMenuOpen(false);
                void exportTrip(id, resolvedTitle, 'fit');
              }}
            />
            <MenuItem
              icon={<Copy color={theme.colors.foreground} size={18} />}
              label={t('trip.menu.duplicate')}
              onPress={() => {
                setMenuOpen(false);
                duplicate();
              }}
            />
            <View
              style={{
                height: 1,
                backgroundColor: theme.colors.border,
                marginVertical: theme.spacing.xs,
              }}
            />
            <MenuItem
              icon={<Trash2 color={theme.colors.destructive} size={18} />}
              label={t('trip.menu.delete')}
              danger
              onPress={() => {
                setMenuOpen(false);
                onDeleteTrip();
              }}
            />
          </View>
        </Pressable>
      </Modal>

      <ConfigSheet
        tripId={id}
        visible={configOpen}
        onClose={() => setConfigOpen(false)}
      />
      <ShareSheet visible={shareOpen} onClose={() => setShareOpen(false)} tripId={id} />
    </Screen>
  );
}
