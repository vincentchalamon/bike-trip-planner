import { useTranslation } from 'react-i18next';
import { updateAccountLocale } from '../api/account';
import { SegmentedControl, type Segment } from '../components/ui';
import { SUPPORTED_LOCALES, type Locale } from './index';

// fr/en switch backed by i18next; changing the language re-renders the tree via
// react-i18next so every translated string updates in place.
//
// It also pushes the choice to the account: the server renders a trip's alerts in
// the account's locale, not in the Accept-Language this client sends (ADR-063),
// so without the PATCH the interface would switch while the trips kept answering
// in the previous language. This switcher only ever renders inside the
// authenticated account screen, so there is no anonymous case to guard.
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
      onChange={(next) => {
        void i18n.changeLanguage(next);
        void updateAccountLocale(next);
      }}
    />
  );
}
