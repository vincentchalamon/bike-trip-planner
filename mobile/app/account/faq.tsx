import { Stack } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { Screen } from '../../src/components/ui';
import { FaqItem } from '../../src/components/account/StaticContent';

export default function AccountFaq() {
  const { t } = useTranslation();

  const items = [1, 2, 3, 4, 5] as const;

  return (
    <Screen scroll>
      <Stack.Screen options={{ headerShown: true, title: t('account.faqTitle') }} />
      {items.map((n) => (
        <FaqItem
          key={n}
          question={t(`account.faqContent.q${n}`)}
          answer={t(`account.faqContent.a${n}`)}
        />
      ))}
    </Screen>
  );
}
