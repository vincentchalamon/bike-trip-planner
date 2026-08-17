import { Text } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { AccommodationData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { Tent } from '../ui/icons';
import { useTheme } from '../../theme';

interface AccommodationBlockProps {
  accommodations: AccommodationData[];
  selectedAccommodation?: AccommodationData | null;
}

// Per-day accommodations. Placeholder content (selected name or candidate list);
// #1038 renders price, source and the selection action.
export function AccommodationBlock({
  accommodations,
  selectedAccommodation,
}: AccommodationBlockProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const items = selectedAccommodation ? [selectedAccommodation] : accommodations;
  return (
    <DataBlock
      title={t('trip.blocks.accommodation')}
      icon={<Tent color={theme.colors.mutedIcon} size={18} />}
      isEmpty={items.length === 0}
      emptyLabel={t('trip.blocks.accommodationEmpty')}
      count={selectedAccommodation ? undefined : accommodations.length}
    >
      {items.map((acc, i) => (
        <Text
          key={i}
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sans,
            fontSize: 14,
          }}
        >
          {acc.name}
        </Text>
      ))}
    </DataBlock>
  );
}
