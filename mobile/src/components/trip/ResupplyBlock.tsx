import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { PoiData, ResupplyData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { ShoppingBag } from '../ui/icons';
import { useTheme } from '../../theme';

// The curated resupply suggestions for one stage (#1099), rendered sectioned by
// role — lunch food, morning water, afternoon water, arrival food — instead of
// the old flat POI dump (#1105). A short help line makes clear these are only
// indicative suggestions.
export function ResupplyBlock({ resupply }: { resupply: ResupplyData }) {
  const { t } = useTranslation();
  const theme = useTheme();

  const sections: { key: string; title: string; pois: PoiData[] }[] = [
    {
      key: 'lunch',
      title: t('trip.blocks.resupplyLunch'),
      pois: resupply.foodAtLunch,
    },
    {
      key: 'waterMorning',
      title: t('trip.blocks.resupplyWaterMorning'),
      pois: resupply.waterMorning ? [resupply.waterMorning] : [],
    },
    {
      key: 'waterAfternoon',
      title: t('trip.blocks.resupplyWaterAfternoon'),
      pois: resupply.waterAfternoon ? [resupply.waterAfternoon] : [],
    },
    {
      key: 'arrival',
      title: t('trip.blocks.resupplyArrival'),
      pois: resupply.foodAtArrival,
    },
  ];
  const filled = sections.filter((s) => s.pois.length > 0);
  const total = filled.reduce((n, s) => n + s.pois.length, 0);

  return (
    <DataBlock
      title={t('trip.blocks.resupply')}
      icon={<ShoppingBag color={theme.colors.mutedIcon} size={18} />}
      isEmpty={total === 0}
      emptyLabel={t('trip.blocks.resupplyEmpty')}
      count={total}
    >
      {filled.map((section) => (
        <View key={section.key} style={{ gap: theme.spacing.xs }}>
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.sansSemibold,
              fontSize: 11,
              letterSpacing: 1,
              textTransform: 'uppercase',
            }}
          >
            {section.title}
          </Text>
          {section.pois.map((poi, i) => (
            <View key={i} style={{ gap: 2 }}>
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
        </View>
      ))}
      {total > 0 ? (
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 12,
            fontStyle: 'italic',
          }}
        >
          {t('trip.blocks.resupplyHelp')}
        </Text>
      ) : null}
    </DataBlock>
  );
}
