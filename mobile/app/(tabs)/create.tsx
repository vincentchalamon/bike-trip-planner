import { useTranslation } from 'react-i18next';
import { EmptyState, Screen } from '../../src/components/ui';
import { Plus } from '../../src/components/ui/icons';
import { useTheme } from '../../src/theme';

// Presentational placeholder: trip creation is wired to the data layer in a
// later sprint (#1031 / Sprint 55). The tab exists so the shell matches target.
export default function Create() {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <Screen padded={false}>
      <EmptyState
        title={t('create.title')}
        description={t('create.description')}
        icon={<Plus color={theme.colors.brand} size={40} />}
      />
    </Screen>
  );
}
