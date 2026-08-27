import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { type ReactNode, useEffect, useMemo, useState } from 'react';
import { Alert, Modal, PanResponder, Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useTranslation } from 'react-i18next';
import {
  ErrorState,
  LoadingState,
  Screen,
  SegmentedControl,
  type Segment,
} from '../../../src/components/ui';
import {
  Copy,
  Download,
  MoreVertical,
  Redo2,
  Settings,
  Share2,
  Trash2,
  Undo2,
} from '../../../src/components/ui/icons';
import {
  ConfigSheet,
  RoadbookView,
  ShareSheet,
  SseStatusIndicator,
  TripMapView,
  TripTitleHeader,
} from '../../../src/components/trip';
import { useTheme } from '../../../src/theme';
import { useExport } from '../../../src/hooks/use-export';
import { confirmDeleteTrip } from '../../../src/hooks/use-trips';
import { useTripLive } from '../../../src/hooks/use-trip-live';
import { useTripMutations } from '../../../src/hooks/use-trip-mutations';
import { useUndoRedo } from '../../../src/hooks/use-undo-redo';
import type { MutationFailure } from '../../../src/store/gating';
import { useTripStore } from '../../../src/store/trip-store';
import { readTripCache } from '../../../src/store/trip-cache';
import { formatFreshness } from '../../../src/lib/freshness';
import { swipeToView } from '../../../src/lib/swipe';

type TripView = 'roadbook' | 'map';

// One row of the header kebab dropdown: an icon + label, with a `danger` variant
// for the destructive delete action.
function MenuItem({
  icon,
  label,
  onPress,
  danger = false,
  disabled = false,
}: {
  icon: ReactNode;
  label: string;
  onPress: () => void;
  danger?: boolean;
  disabled?: boolean;
}) {
  const theme = useTheme();
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={onPress}
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        gap: theme.spacing.md,
        paddingVertical: theme.spacing.sm,
        paddingHorizontal: theme.spacing.md,
        opacity: disabled ? 0.4 : 1,
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
  const insets = useSafeAreaInsets();
  const [view, setView] = useState<TripView>('roadbook');
  // Mount TripMapView only once the Map tab is first opened, then keep it mounted
  // (never re-mount). Gating the first mount preserves ADR-057's "route fetched
  // only when the map is actually viewed" — TripMapView calls useTripRoute()
  // unconditionally — while still avoiding the expensive native re-mount (#1176).
  const [hasViewedMap, setHasViewedMap] = useState(view === 'map');
  useEffect(() => {
    if (view === 'map') setHasViewedMap(true);
  }, [view]);
  const [configOpen, setConfigOpen] = useState(false);
  const [configSection, setConfigSection] = useState<'dates' | undefined>(undefined);
  const [shareOpen, setShareOpen] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);

  // Horizontal swipe between the roadbook and map tabs, mirroring the segmented
  // control. Claims only a decisive horizontal gesture so the roadbook's
  // vertical scroll (and, over the native map, its own pan) keep working.
  const panResponder = useMemo(
    () =>
      PanResponder.create({
        onMoveShouldSetPanResponder: (_, g) =>
          Math.abs(g.dx) > 24 && Math.abs(g.dx) > Math.abs(g.dy) * 1.5,
        onPanResponderRelease: (_, g) => {
          const next = swipeToView(g.dx);
          if (next) setView(next);
        },
      }),
    [],
  );

  function openDatesConfig() {
    setConfigSection('dates');
    setConfigOpen(true);
  }

  // Hydrate the shared store from /detail and keep it live via SSE. The child
  // views render straight from the store, so a stage_updated event reconciled by
  // the core reducers updates them in place (no ad-hoc local state).
  useTripLive(id);

  const title = useTripStore((s) => s.title);
  const computing = useTripStore((s) => s.computing);
  const loading = useTripStore((s) => s.loading);
  const error = useTripStore((s) => s.error);

  // Offline "synced X ago" indicator (#1147): read the cache timestamp once the
  // trip has loaded (a fresh online load has just re-stamped it).
  const [syncedAt, setSyncedAt] = useState<number | null>(null);
  useEffect(() => {
    if (loading) return;
    let cancelled = false;
    void readTripCache(id).then((cached) => {
      if (!cancelled) setSyncedAt(cached?.syncedAt ?? null);
    });
    return () => {
      cancelled = true;
    };
  }, [id, loading]);

  const onFailure = (reason: MutationFailure) =>
    Alert.alert(t('common.error'), t(`trip.edit.reason.${reason}`));
  const mutations = useTripMutations(id, onFailure);
  const { canUndo, canRedo, undo, redo } = useUndoRedo();
  const { exportTrip } = useExport(() =>
    Alert.alert(t('export.failedTitle'), t('export.failedMessage')),
  );

  const resolvedTitle = title ?? t('trip.title');

  const segments: readonly Segment<TripView>[] = [
    { value: 'roadbook', label: t('trip.segmentRoadbook') },
    { value: 'map', label: t('trip.segmentMap') },
  ];

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

  // Before the trip is hydrated, hide the native header: otherwise it shows the
  // raw route name ("trip/[id]/index") next to the spinner. The header (title +
  // ⋯ menu) appears only once the trip is loaded.
  if (loading) {
    return (
      <Screen padded={false} edges={['top', 'left', 'right']}>
        <Stack.Screen options={{ headerShown: false }} />
        <LoadingState />
      </Screen>
    );
  }

  if (error) {
    return (
      <Screen padded={false} edges={['top', 'left', 'right']}>
        <Stack.Screen options={{ headerShown: false }} />
        <ErrorState
          title={t('common.error')}
          description={t(error, { defaultValue: error })}
        />
      </Screen>
    );
  }

  return (
    <Screen padded={false} edges={['top', 'left', 'right']}>
      <Stack.Screen
        options={{
          headerShown: true,
          headerTitle: () => <TripTitleHeader tripId={id} />,
          headerRight: () => (
            <View
              style={{
                flexDirection: 'row',
                alignItems: 'center',
                gap: theme.spacing.sm,
              }}
            >
              <SseStatusIndicator computing={computing} />
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('trip.menu.open')}
                onPress={() => setMenuOpen((open) => !open)}
                hitSlop={8}
                style={{ padding: theme.spacing.xs }}
              >
                <MoreVertical color={theme.colors.foreground} size={22} />
              </Pressable>
            </View>
          ),
        }}
      />
      <View
        style={{
          paddingHorizontal: theme.spacing.base,
          paddingTop: theme.spacing.sm,
          paddingBottom: theme.spacing.sm,
        }}
      >
        <SegmentedControl segments={segments} value={view} onChange={setView} />
        {syncedAt !== null ? (
          <Text
            style={{
              marginTop: theme.spacing.xs,
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.sans,
              fontSize: 12,
              textAlign: 'center',
            }}
          >
            {t('freshness.synced', {
              ago: formatFreshness(t, syncedAt, Date.now()),
            })}
          </Text>
        ) : null}
      </View>

      <View style={{ flex: 1 }} {...panResponder.panHandlers}>
        {/* Once opened, both tabs stay mounted (perf #1176): MapLibre's native
            view is expensive to tear down and recreate (GPU textures, tile
            cache), so swapping it out on every Roadbook<->Carte toggle re-pays
            that cost each time. Hiding the inactive one via `display: none`
            keeps a single long-lived map instance. The map is only mounted
            after its first open (`hasViewedMap`) so a rider who never taps
            "Carte" never triggers TripMapView's eager /route fetch (ADR-057). */}
        <View style={{ flex: 1, display: view === 'map' ? 'flex' : 'none' }}>
          {hasViewedMap ? <TripMapView /> : null}
        </View>
        <View style={{ flex: 1, display: view === 'roadbook' ? 'flex' : 'none' }}>
          <RoadbookView id={id} onConfigureDates={openDatesConfig} />
        </View>
      </View>

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
              // Sit just below the header (status bar + ~56dp nav bar). A fixed
              // 48px from the screen top landed on the ⋮ button itself, so the
              // button was covered and could never toggle the menu shut.
              top: insets.top + 56,
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
            {/* Undo/redo (#1178, maquette roadbook ⋯): restore the previous /
                next roadbook state. Disabled with empty history and, per the
                #1166 write gate, while the trip is started or the connection is
                degraded (offline / API-down). */}
            {/* Undo and redo sit side by side (10-account roadbook maquette
                `.undo` row), split by a vertical divider (#1214). */}
            <View style={{ flexDirection: 'row', alignItems: 'stretch' }}>
              <View style={{ flex: 1 }}>
                <MenuItem
                  icon={<Undo2 color={theme.colors.foreground} size={18} />}
                  label={t('trip.menu.undo')}
                  disabled={!canUndo}
                  onPress={() => {
                    setMenuOpen(false);
                    undo();
                  }}
                />
              </View>
              <View style={{ width: 1, backgroundColor: theme.colors.border }} />
              <View style={{ flex: 1 }}>
                <MenuItem
                  icon={<Redo2 color={theme.colors.foreground} size={18} />}
                  label={t('trip.menu.redo')}
                  disabled={!canRedo}
                  onPress={() => {
                    setMenuOpen(false);
                    redo();
                  }}
                />
              </View>
            </View>
            <View
              style={{
                height: 1,
                backgroundColor: theme.colors.border,
                marginVertical: theme.spacing.xs,
              }}
            />
            <MenuItem
              icon={<Settings color={theme.colors.foreground} size={18} />}
              label={t('trip.menu.config')}
              onPress={() => {
                setMenuOpen(false);
                setConfigSection(undefined);
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
        initialSection={configSection}
        onClose={() => setConfigOpen(false)}
      />
      <ShareSheet visible={shareOpen} onClose={() => setShareOpen(false)} tripId={id} />
    </Screen>
  );
}
