import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { EventData } from '@btp/core';
import { DataBlock } from './DataBlock';
import {
  DEFAULT_VISIBLE_EVENTS,
  eventTypeKey,
  formatEventDateRange,
  sortEvents,
} from './event-utils';
import { Calendar } from '../ui/icons';
import { useTheme } from '../../theme';

// Per-day cultural events near the stage end: sorted by start date, with a
// date range, type label and starting price. Collapsed to the first few with a
// "see more" toggle (mirrors the web events-panel).
export function EventsBlock({ events }: { events: EventData[] }) {
  const { t, i18n } = useTranslation();
  const theme = useTheme();
  const [showAll, setShowAll] = useState(false);

  const sorted = sortEvents(events);
  const visible = showAll ? sorted : sorted.slice(0, DEFAULT_VISIBLE_EVENTS);
  const hidden = sorted.length - visible.length;

  return (
    <DataBlock
      title={t('trip.blocks.events')}
      icon={<Calendar color={theme.colors.mutedIcon} size={18} />}
      isEmpty={events.length === 0}
      emptyLabel={t('trip.blocks.eventsEmpty')}
      count={events.length}
    >
      {visible.map((event, i) => {
        // Explicit branches: the i18n key type is a literal union, so a
        // templated `eventType.${key}` is rejected by tsc.
        let typeLabel: string;
        switch (eventTypeKey(event.type)) {
          case 'festival':
            typeLabel = t('trip.blocks.eventType.festival');
            break;
          case 'exhibition':
            typeLabel = t('trip.blocks.eventType.exhibition');
            break;
          case 'musicEvent':
            typeLabel = t('trip.blocks.eventType.musicEvent');
            break;
          case 'fairOrShow':
            typeLabel = t('trip.blocks.eventType.fairOrShow');
            break;
          default:
            typeLabel = event.type;
        }
        const meta = [
          formatEventDateRange(event.startDate, event.endDate, i18n.language),
          typeLabel,
          event.priceMin != null
            ? t('trip.blocks.eventsFromPrice', { price: event.priceMin })
            : null,
        ]
          .filter(Boolean)
          .join(' · ');
        return (
          <View key={`${event.name}-${i}`}>
            <Text
              style={{
                color: theme.colors.foreground,
                fontFamily: theme.fonts.sansMedium,
                fontSize: 14,
              }}
            >
              {event.name}
            </Text>
            <Text
              style={{
                color: theme.colors.mutedForeground,
                fontFamily: theme.fonts.mono,
                fontSize: 13,
              }}
            >
              {meta}
            </Text>
          </View>
        );
      })}
      {hidden > 0 ? (
        <Pressable
          hitSlop={8}
          onPress={() => setShowAll(true)}
          accessibilityRole="button"
        >
          <Text
            style={{
              color: theme.colors.accentBrand,
              fontFamily: theme.fonts.sansMedium,
              fontSize: 13,
            }}
          >
            {t('trip.blocks.eventsShowMore', { count: hidden })}
          </Text>
        </Pressable>
      ) : null}
    </DataBlock>
  );
}
