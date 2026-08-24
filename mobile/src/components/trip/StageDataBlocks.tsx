import { useCallback } from 'react';
import { Alert, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { StageData } from '@btp/core';
import { ACCOMMODATION_RADIUS_STEP_KM } from '@btp/core/constants';
import { AlertsBlock } from './AlertsBlock';
import { WeatherBlock } from './WeatherBlock';
import { ResupplyBlock } from './ResupplyBlock';
import { AccommodationBlock } from './AccommodationBlock';
import { SupplyBlock } from './SupplyBlock';
import { EventsBlock } from './EventsBlock';
import { Bike } from '../ui/icons';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';
import { useOfflineStore } from '../../store/offline-store';
import { useTripMutations } from '../../hooks/use-trip-mutations';
import type { MutationFailure } from '../../store/gating';
import type { TFunction } from 'i18next';

// Surface a mutation failure as a native alert. Conflict maps to the "list
// refreshed" message (the runner has already triggered a re-scan); the routing
// gate maps to the out-of-zone message.
export function notifyFailure(t: TFunction, reason: MutationFailure): void {
  switch (reason) {
    case 'locked':
      Alert.alert(t('trip.lockedTitle'), t('trip.lockedMessage'));
      return;
    case 'out_of_zone':
      Alert.alert(t('trip.outOfZoneTitle'), t('trip.outOfZoneMessage'));
      return;
    case 'conflict':
      Alert.alert(
        t('trip.accommodationStaleTitle'),
        t('trip.accommodationStaleMessage'),
      );
      return;
    case 'offline':
      Alert.alert(t('trip.offlineTitle'), t('trip.offlineMessage'));
      return;
    case 'api_unavailable':
      Alert.alert(t('trip.apiUnavailableTitle'), t('trip.apiUnavailableMessage'));
      return;
    default:
      Alert.alert(t('trip.editFailedTitle'), t('trip.editFailedMessage'));
  }
}

// A stage largely follows a signed cycle route (EuroVelo, voie verte...): show
// the badge only above a meaningful share so it stays a positive signal rather
// than noise (ADR-040, mirrors the web threshold).
const CYCLE_NETWORK_THRESHOLD = 0.5;

function CycleNetworkBadge({ fraction }: { fraction: number }) {
  const { t } = useTranslation();
  const theme = useTheme();
  if (fraction < CYCLE_NETWORK_THRESHOLD) return null;
  const bg = theme.scheme === 'dark' ? 'rgba(20,83,45,0.35)' : '#dcfce7';
  const fg = theme.scheme === 'dark' ? '#86efac' : '#166534';
  return (
    <View
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        alignSelf: 'flex-start',
        gap: theme.spacing.xs,
        backgroundColor: bg,
        borderRadius: theme.radius.full,
        paddingHorizontal: theme.spacing.md,
        paddingVertical: theme.spacing.xs,
      }}
    >
      <Bike color={fg} size={14} />
      <Text style={{ color: fg, fontFamily: theme.fonts.sansMedium, fontSize: 12 }}>
        {t('trip.blocks.cycleNetwork', { percent: Math.round(fraction * 100) })}
      </Text>
    </View>
  );
}

interface StageDataBlocksProps {
  stage: StageData;
  // Zero-based index of this stage. Enables the accommodation / POI-waypoint
  // editing affordances (#1045); when absent the blocks stay read-only.
  stageIndex?: number;
  // Routes a `navigate` alert action to the map segment (#1040).
  onAlertNavigate?: (segments: [number, number][][]) => void;
}

// Every per-day data family for one stage, stacked: cycle-network badge, then
// alerts, weather, POI, accommodations, supply and events. Data flows live from
// the store (reconciled by @btp/core); each block owns its own empty state.
// When a `stageIndex` is given the accommodation and POI blocks become editable
// (select / deselect / widen radius / insert POI-waypoint), gated on lock,
// connectivity and — for the POI-waypoint reroute — the trip zone (#1045).
export function StageDataBlocks({
  stage,
  stageIndex,
  onAlertNavigate,
}: StageDataBlocksProps) {
  const theme = useTheme();
  const { t } = useTranslation();
  const tripId = useTripStore((s) => s.tripId);
  const isLocked = useTripStore((s) => s.isLocked);
  const outOfZone = useTripStore((s) => s.outOfZone);
  const isOnline = useOfflineStore((s) => s.isOnline);
  const apiReachable = useOfflineStore((s) => s.apiReachable);
  const onFailure = useCallback(
    (reason: MutationFailure) => notifyFailure(t, reason),
    [t],
  );
  const mutations = useTripMutations(tripId ?? '', onFailure);
  const editable = stageIndex !== undefined && tripId !== null;
  const disabled = isLocked || !isOnline || !apiReachable;
  // Order mirrors the Spike-UX stage-detail mockup: weather, then alerts,
  // events, arrival accommodation, supply and finally the sectioned resupply
  // suggestions (#1105).
  return (
    <View style={{ gap: theme.spacing.md }}>
      <CycleNetworkBadge fraction={stage.onCycleNetwork ?? 0} />
      <WeatherBlock weather={stage.weather} />
      <AlertsBlock
        alerts={stage.alerts}
        stageKey={stage.dayNumber}
        onNavigate={onAlertNavigate}
      />
      <EventsBlock events={stage.events} />
      <AccommodationBlock
        accommodations={stage.accommodations}
        selectedAccommodation={stage.selectedAccommodation}
        {...(editable && {
          radiusKm: stage.accommodationSearchRadiusKm,
          disabled,
          outOfZone,
          onSelect: (accIndex: number) =>
            void mutations.selectAccommodation(stageIndex, accIndex),
          onDeselect: () => void mutations.deselectAccommodation(stageIndex),
          onExpandRadius: () =>
            void mutations.scanAccommodations(
              stage.accommodationSearchRadiusKm + ACCOMMODATION_RADIUS_STEP_KM,
              stageIndex,
            ),
          // A geocoding failure surfaces as 'validation'; map it to an actionable
          // address message, delegating every other reason to the shared handler.
          onAddManual: (data) =>
            mutations.addManualAccommodation(stageIndex, data, (reason) =>
              reason === 'validation'
                ? Alert.alert(
                    t('trip.accommodationGeocodeFailedTitle'),
                    t('trip.accommodationGeocodeFailedMessage'),
                  )
                : notifyFailure(t, reason),
            ),
        })}
      />
      <SupplyBlock supplyTimeline={stage.supplyTimeline} />
      <ResupplyBlock resupply={stage.resupply} />
    </View>
  );
}
