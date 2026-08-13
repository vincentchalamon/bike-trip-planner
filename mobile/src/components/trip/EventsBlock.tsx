import { Text } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { EventData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { Calendar } from '../ui/icons';
import { useTheme } from '../../theme';

// Per-day cultural events near the stage end. Placeholder content (name list);
// #1038 renders dates, price and the source link.
export function EventsBlock({ events }: { events: EventData[] }) {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <DataBlock
      title={t('trip.blocks.events')}
      icon={<Calendar color={theme.colors.mutedIcon} size={18} />}
      isEmpty={events.length === 0}
      emptyLabel={t('trip.blocks.eventsEmpty')}
      count={events.length}
    >
      {events.map((event, i) => (
        <Text
          key={i}
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sans,
            fontSize: 14,
          }}
        >
          {event.name}
        </Text>
      ))}
    </DataBlock>
  );
}
