import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { StageData } from '@btp/core';
import { AlertsBlock } from './AlertsBlock';
import { WeatherBlock } from './WeatherBlock';
import { PoiBlock } from './PoiBlock';
import { AccommodationBlock } from './AccommodationBlock';
import { SupplyBlock } from './SupplyBlock';
import { EventsBlock } from './EventsBlock';
import { Bike } from '../ui/icons';
import { useTheme } from '../../theme';

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
  // Routes a `navigate` alert action to the map segment (#1040).
  onAlertNavigate?: (segments: [number, number][][]) => void;
}

// Every per-day data family for one stage, stacked: cycle-network badge, then
// alerts, weather, POI, accommodations, supply and events. Data flows live from
// the store (reconciled by @btp/core); each block owns its own empty state.
export function StageDataBlocks({ stage, onAlertNavigate }: StageDataBlocksProps) {
  const theme = useTheme();
  return (
    <View style={{ gap: theme.spacing.sm }}>
      <CycleNetworkBadge fraction={stage.onCycleNetwork ?? 0} />
      <AlertsBlock alerts={stage.alerts} onNavigate={onAlertNavigate} />
      <WeatherBlock weather={stage.weather} />
      <PoiBlock pois={stage.pois} />
      <AccommodationBlock
        accommodations={stage.accommodations}
        selectedAccommodation={stage.selectedAccommodation}
      />
      <SupplyBlock supplyTimeline={stage.supplyTimeline} />
      <EventsBlock events={stage.events} />
    </View>
  );
}
