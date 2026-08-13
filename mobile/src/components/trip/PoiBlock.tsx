import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { PoiData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { MapPin } from '../ui/icons';
import { useTheme } from '../../theme';

// Per-day points of interest: name, category and distance from the stage start.
export function PoiBlock({ pois }: { pois: PoiData[] }) {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <DataBlock
      title={t('trip.blocks.poi')}
      icon={<MapPin color={theme.colors.mutedIcon} size={18} />}
      isEmpty={pois.length === 0}
      emptyLabel={t('trip.blocks.poiEmpty')}
      count={pois.length}
    >
      {pois.map((poi, i) => (
        <View key={i}>
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
        </View>
      ))}
    </DataBlock>
  );
}
