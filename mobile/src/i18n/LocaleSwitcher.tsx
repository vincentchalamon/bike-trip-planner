import { useTranslation } from 'react-i18next';
import { SegmentedControl, type Segment } from '../components/ui';
import { SUPPORTED_LOCALES, type Locale } from './index';

// fr/en switch backed by i18next; changing the language re-renders the tree via
// react-i18next so every translated string updates in place.
export function LocaleSwitcher() {
  const { i18n, t } = useTranslation();
  const segments: readonly Segment<Locale>[] = SUPPORTED_LOCALES.map((code) => ({
    value: code,
    label: t(`language.${code}`),
  }));
  const current: Locale = i18n.language === 'en' ? 'en' : 'fr';

  return (
    <SegmentedControl
      segments={segments}
      value={current}
      onChange={(next) => void i18n.changeLanguage(next)}
    />
  );
}
