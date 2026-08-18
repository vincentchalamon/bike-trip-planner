import { Alert } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button } from '../ui';
import { Download } from '../ui/icons';
import { useTheme } from '../../theme';
import { confirmExportFormat, useExport } from '../../hooks/use-export';

interface ExportButtonProps {
  tripId: string;
  tripTitle: string;
  // When set, exports this stage instead of the whole trip as GPX/FIT (#1047).
  // `dayNumber` is the 1-based day the export route resolves on server-side —
  // not the 0-based array index.
  stage?: { dayNumber: number };
}

// Native share-sheet export (#1047): pick GPX/FIT via a native alert, download the
// file, then hand it to expo-sharing so the user can save/send it. Self-contained
// (icon-only, ghost variant) so it slots into a header row without disturbing
// sibling actions — the trip header also grows a config panel (#1046) and a share
// action (#1048).
export function ExportButton({ tripId, tripTitle, stage }: ExportButtonProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const { exporting, exportTrip, exportStage } = useExport(() =>
    Alert.alert(t('export.failedTitle'), t('export.failedMessage')),
  );

  const label = stage ? t('export.stage') : t('export.trip');

  return (
    <Button
      variant="ghost"
      size="sm"
      label={label}
      icon={<Download color={theme.colors.mutedIcon} size={18} />}
      loading={exporting}
      onPress={() =>
        confirmExportFormat({
          title: label,
          gpxLabel: t('export.gpx'),
          fitLabel: t('export.fit'),
          cancelLabel: t('export.cancel'),
          onSelect: (format) =>
            void (stage
              ? exportStage(tripId, stage.dayNumber, tripTitle, format)
              : exportTrip(tripId, tripTitle, format)),
        })
      }
    />
  );
}
