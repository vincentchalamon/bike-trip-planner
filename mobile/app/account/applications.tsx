import { useCallback, useEffect, useState } from 'react';
import { Alert, Text, View } from 'react-native';
import { Stack } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { Button, Card, EmptyState, ErrorState, LoadingState, Screen } from '../../src/components/ui';
import { Plug } from '../../src/components/ui/icons';
import {
  fetchAuthorizedApplications,
  revokeAuthorizedApplication,
  type AuthorizedApplication,
} from '../../src/api/account';
import { useTheme } from '../../src/theme';

/**
 * The applications that can act for this account, and the way to take that back (#1308).
 *
 * A screen rather than a section, because the account tab is a list of destinations and this
 * one carries rows of its own. No maquette exists for it: it borrows the spacing, the Card and
 * the state placeholders of its neighbours rather than inventing a pattern.
 *
 * The application's name is text it published about itself. It is rendered on its own line and
 * never interpolated into a sentence — including in the confirmation, where the host is the
 * line that actually identifies who is being cut off, since it is the part of the identity the
 * application could not choose.
 */
export default function AccountApplications() {
  const { t, i18n } = useTranslation();
  const theme = useTheme();

  const [applications, setApplications] = useState<AuthorizedApplication[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [revoking, setRevoking] = useState<string | null>(null);

  const load = useCallback(async () => {
    setFailed(false);
    setApplications(null);
    const list = await fetchAuthorizedApplications();
    if (list === null) {
      setFailed(true);
      return;
    }
    setApplications(list);
  }, []);

  useEffect(() => void load(), [load]);

  const revoke = useCallback(
    async (application: AuthorizedApplication) => {
      setRevoking(application.id);
      const ok = await revokeAuthorizedApplication(application.id);
      setRevoking(null);

      if (!ok) {
        Alert.alert(t('account.applications.title'), t('account.applications.revokeFailed'));
        return;
      }
      // Drop the row locally: the list is small and the server has just said this one is gone.
      setApplications((current) => (current ?? []).filter((one) => one.id !== application.id));
    },
    [t],
  );

  const confirmRevoke = useCallback(
    (application: AuthorizedApplication) => {
      Alert.alert(
        t('account.applications.confirmTitle'),
        // The name and host are their own lines under the sentence, never inside it.
        `${t('account.applications.confirmBody')}\n\n${application.name}\n${application.host}`,
        [
          { text: t('account.applications.cancel'), style: 'cancel' },
          {
            text: t('account.applications.revoke'),
            style: 'destructive',
            onPress: () => void revoke(application),
          },
        ],
      );
    },
    [revoke, t],
  );

  return (
    <Screen scroll>
      <Stack.Screen options={{ headerShown: true, title: t('account.applications.title') }} />

      {failed ? (
        <ErrorState description={t('account.applications.loadFailed')} onRetry={() => void load()} />
      ) : applications === null ? (
        <LoadingState label={t('account.applications.loading')} />
      ) : applications.length === 0 ? (
        <EmptyState
          title={t('account.applications.emptyTitle')}
          description={t('account.applications.emptyBody')}
          icon={<Plug color={theme.colors.mutedIcon} size={28} />}
        />
      ) : (
        <View style={{ gap: theme.spacing.md }}>
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.sans,
              fontSize: 14,
              lineHeight: 20,
            }}
          >
            {t('account.applications.description')}
          </Text>

          {applications.map((application) => (
            <Card key={application.id}>
              <View style={{ flexDirection: 'row', alignItems: 'flex-start', gap: theme.spacing.md }}>
                <Plug color={theme.colors.mutedIcon} size={20} />
                <View style={{ flex: 1 }}>
                  <Text
                    style={{
                      color: theme.colors.foreground,
                      fontFamily: theme.fonts.sansSemibold,
                      fontSize: 16,
                    }}
                  >
                    {application.name}
                  </Text>
                  <Text
                    style={{
                      color: theme.colors.mutedForeground,
                      fontFamily: theme.fonts.mono,
                      fontSize: 12,
                      marginTop: 2,
                    }}
                  >
                    {application.host}
                  </Text>

                  {application.scopes.map((scope) => (
                    <Text
                      key={scope}
                      style={{
                        color: theme.colors.mutedForeground,
                        fontFamily: theme.fonts.sans,
                        fontSize: 13,
                        marginTop: 4,
                      }}
                    >
                      {scopeLabel(scope)}
                    </Text>
                  ))}

                  <Text
                    style={{
                      color: theme.colors.mutedForeground,
                      fontFamily: theme.fonts.sans,
                      fontSize: 12,
                      marginTop: theme.spacing.sm,
                    }}
                  >
                    {t('account.applications.authorizedOn', {
                      date: formatDate(application.authorizedAt, i18n.language),
                    })}
                    {' · '}
                    {application.lastUsedAt
                      ? t('account.applications.lastUsedOn', {
                          date: formatDate(application.lastUsedAt, i18n.language),
                        })
                      : t('account.applications.neverUsed')}
                  </Text>

                  <View style={{ marginTop: theme.spacing.md, alignSelf: 'flex-start' }}>
                    <Button
                      label={t('account.applications.revoke')}
                      variant="destructive"
                      size="sm"
                      loading={revoking === application.id}
                      onPress={() => confirmRevoke(application)}
                    />
                  </View>
                </View>
              </View>
            </Card>
          ))}

          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.sans,
              fontSize: 12,
              lineHeight: 18,
              marginTop: theme.spacing.sm,
            }}
          >
            {t('account.applications.footer')}
          </Text>
        </View>
      )}
    </Screen>
  );

  /**
   * Falls back to the raw scope: these are the scopes **as granted**, which may name a
   * permission the current catalogue no longer describes.
   *
   * `nsSeparator: false` is not optional. i18next reads `:` as the separator between a
   * namespace and a key, and every scope carries one (`trips:read`), so the default lookup
   * asks a namespace named `account.applications.scope.trips` for a key `read` and finds
   * nothing — silently, which reads as "the catalogue does not know this scope".
   */
  function scopeLabel(scope: string): string {
    const key = `account.applications.scope.${scope}`;
    const literalKey = { nsSeparator: false } as const;
    return i18n.exists(key, literalKey) ? t(key as never, literalKey) : scope;
  }
}

function formatDate(value: string | null, locale: string): string {
  if (!value) return '';
  return new Date(value).toLocaleDateString(locale, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
}
