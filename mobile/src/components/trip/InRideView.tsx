import { type ReactNode } from 'react';
import { Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '../ui';
import { HelpCircle, Info, Navigation, WifiOff } from '../ui/icons';
import { useTheme } from '../../theme';
import { useOfflineStore } from '../../store/offline-store';
import {
  useForegroundLocation,
  type ForegroundLocation,
} from '../../hooks/use-foreground-location';

// The in-ride screen body (#1149, maquette 08-in-ride + theme pass in #1094).
// It hosts the topbar connectivity dot, the "route unchanged / OSM data"
// disclaimer, the foreground GPS status, an offline badge (nearby-pois needs
// the network), a floating help bubble, and a `poiPanel` slot that #1150 fills
// with the deterministic POI finder (ADR-048). Kept as a plain component (not
// the route) so it renders in tests without a mounted navigator.
export function InRideView({
  tripId: _tripId,
  poiPanel,
  onHelp,
}: {
  // Carried for #1150 (POST /trips/{id}/nearby-pois); unused in this structural pass.
  tripId: string;
  // Extension slot for the #1150 POI finder panel. A render function receives the
  // screen's single foreground-GPS fix so the panel searches from the real
  // position without opening a second location subscription; a plain node is
  // still accepted (kept for the structural #1149 tests).
  poiPanel?: ReactNode | ((location: ForegroundLocation) => ReactNode);
  onHelp?: () => void;
}) {
  const { t } = useTranslation();
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const isOnline = useOfflineStore((s) => s.isOnline);
  const { permission, position } = useForegroundLocation();

  return (
    <View style={{ flex: 1, backgroundColor: theme.colors.background }}>
      {/* Connectivity status (maquette 08-in-ride topbar, #1094) — the chevron
          + title live in the native Stack header (themed globally, see
          _layout.tsx); this is the third topbar element, kept here so it stays
          covered by this component's tests. */}
      <View
        style={{
          flexDirection: 'row',
          justifyContent: 'flex-end',
          alignItems: 'center',
          gap: theme.spacing.xs,
          paddingHorizontal: theme.spacing.base,
          paddingTop: theme.spacing.sm,
        }}
      >
        <View
          style={{
            width: 8,
            height: 8,
            borderRadius: theme.radius.full,
            backgroundColor: isOnline ? theme.colors.successInk : theme.colors.destructive,
          }}
        />
        <Text
          style={{
            color: isOnline ? theme.colors.successInk : theme.colors.destructive,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 12,
          }}
        >
          {isOnline ? t('trip.inRide.onlineBadge') : t('trip.inRide.offlineBadge')}
        </Text>
      </View>

      {/* Disclaimer banner (maquette 08-in-ride, #1094) — always shown, the
          bold first sentence carries the reassurance, the rest points at the
          OSM data-quality caveat. */}
      <View
        style={{
          flexDirection: 'row',
          alignItems: 'flex-start',
          gap: theme.spacing.sm,
          margin: theme.spacing.base,
          marginTop: theme.spacing.xs,
          padding: theme.spacing.md,
          backgroundColor: theme.colors.muted,
          borderRadius: theme.radius.lg,
          borderWidth: 1,
          borderColor: theme.colors.border,
        }}
      >
        <Info color={theme.colors.mutedIcon} size={18} />
        <Text
          style={{
            flex: 1,
            fontFamily: theme.fonts.sans,
            fontSize: 13,
            color: theme.colors.mutedForeground,
          }}
        >
          <Text
            style={{
              fontFamily: theme.fonts.sansSemibold,
              color: theme.colors.foreground,
            }}
          >
            {t('trip.inRide.disclaimerStrong')}
          </Text>{' '}
          {t('trip.inRide.disclaimer')}
        </Text>
      </View>

      {!isOnline ? (
        <View
          accessibilityRole="alert"
          accessibilityLabel={t('trip.inRide.offlineBadge')}
          style={{
            flexDirection: 'row',
            alignItems: 'center',
            gap: theme.spacing.sm,
            margin: theme.spacing.base,
            padding: theme.spacing.md,
            backgroundColor: theme.colors.muted,
            borderRadius: theme.radius.lg,
            borderWidth: 1,
            borderColor: theme.colors.border,
          }}
        >
          <WifiOff color={theme.colors.destructive} size={18} />
          <View style={{ flex: 1 }}>
            <Text
              style={{
                color: theme.colors.foreground,
                fontFamily: theme.fonts.sansSemibold,
                fontSize: 14,
              }}
            >
              {t('trip.inRide.offlineBadge')}
            </Text>
            <Text
              style={{
                color: theme.colors.mutedForeground,
                fontFamily: theme.fonts.sans,
                fontSize: 13,
              }}
            >
              {t('trip.inRide.offlineHint')}
            </Text>
          </View>
        </View>
      ) : null}

      <View style={{ flex: 1 }}>
        {permission === 'denied' ? (
          <EmptyState
            title={t('trip.inRide.locationDeniedTitle')}
            description={t('trip.inRide.locationDenied')}
          />
        ) : position ? (
          <View
            style={{
              flexDirection: 'row',
              alignItems: 'center',
              gap: theme.spacing.sm,
              padding: theme.spacing.base,
            }}
          >
            <Navigation color={theme.colors.brandFill} size={18} />
            <Text
              accessibilityLabel={t('trip.inRide.positionA11y')}
              style={{
                color: theme.colors.foreground,
                fontFamily: theme.fonts.mono,
                fontSize: 14,
              }}
            >
              {t('trip.inRide.coords', {
                lat: position.latitude.toFixed(5),
                lng: position.longitude.toFixed(5),
              })}
            </Text>
          </View>
        ) : (
          <EmptyState title={t('trip.inRide.locationWaiting')} />
        )}

        {/* #1150 mounts its nearby-pois finder here. */}
        {typeof poiPanel === 'function' ? poiPanel({ permission, position }) : poiPanel}
      </View>

      <Pressable
        accessibilityRole="button"
        accessibilityLabel={t('trip.inRide.helpA11y')}
        onPress={onHelp}
        style={{
          position: 'absolute',
          right: theme.spacing.lg,
          bottom: insets.bottom + theme.spacing.lg,
          width: 52,
          height: 52,
          alignItems: 'center',
          justifyContent: 'center',
          backgroundColor: theme.colors.brandFill,
          borderRadius: theme.radius.full,
          ...theme.shadows.medium,
        }}
      >
        <HelpCircle color={theme.colors.primaryForeground} size={24} />
      </Pressable>
    </View>
  );
}
