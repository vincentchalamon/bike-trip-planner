import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { PoiData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { Button } from '../ui';
import { MapPin, Plus } from '../ui/icons';
import { useTheme } from '../../theme';

interface PoiBlockProps {
  pois: PoiData[];
  // Editing affordances (#1045). Provided only in an editable context; when
  // absent the block stays read-only.
  disabled?: boolean;
  // Inserting a POI waypoint reroutes the stage via Valhalla, which has no tiles
  // outside the provisioned area — the action is gated out of zone.
  outOfZone?: boolean;
  onAddWaypoint?: (lat: number, lon: number) => void;
}

// Per-day points of interest: name, category and distance from the stage start.
// In an editable stage (#1045) each POI can be inserted as a route waypoint,
// blocked (with a hint) when the trip is out of zone.
export function PoiBlock({
  pois,
  disabled = false,
  outOfZone = false,
  onAddWaypoint,
}: PoiBlockProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const editable = Boolean(onAddWaypoint);
  return (
    <DataBlock
      title={t('trip.blocks.poi')}
      icon={<MapPin color={theme.colors.mutedIcon} size={18} />}
      isEmpty={pois.length === 0}
      emptyLabel={t('trip.blocks.poiEmpty')}
      count={pois.length}
    >
      {pois.map((poi, i) => (
        <View key={i} style={{ gap: theme.spacing.xs }}>
          <Text
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.sans,
              fontSize: 14,
            }}
          >
            {poi.name}
          </Text>
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.mono,
              fontSize: 13,
            }}
          >
            {poi.category}
            {poi.distanceFromStart != null
              ? ` · ${t('trip.blocks.distanceKm', { distance: Math.round(poi.distanceFromStart) })}`
              : ''}
          </Text>
          {editable ? (
            <Button
              variant="secondary"
              size="sm"
              label={t('trip.blocks.poiAddWaypoint')}
              icon={<Plus color={theme.colors.secondaryForeground} size={14} />}
              disabled={disabled || outOfZone}
              onPress={() => onAddWaypoint?.(poi.lat, poi.lon)}
            />
          ) : null}
        </View>
      ))}
      {editable && outOfZone ? (
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 13,
          }}
        >
          {t('trip.blocks.poiWaypointOutOfZone')}
        </Text>
      ) : null}
    </DataBlock>
  );
}
