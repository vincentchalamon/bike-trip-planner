import { Text } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { AlertData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { AlertTriangle } from '../ui/icons';
import { useTheme } from '../../theme';

// Per-day alerts. Placeholder content (message list); #1038 renders severity,
// actions and map highlights.
export function AlertsBlock({ alerts }: { alerts: AlertData[] }) {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <DataBlock
      title={t('trip.blocks.alerts')}
      icon={<AlertTriangle color={theme.colors.mutedIcon} size={18} />}
      isEmpty={alerts.length === 0}
      emptyLabel={t('trip.blocks.alertsEmpty')}
      count={alerts.length}
    >
      {alerts.map((alert, i) => (
        <Text
          key={i}
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sans,
            fontSize: 14,
          }}
        >
          {alert.message}
        </Text>
      ))}
    </DataBlock>
  );
}
