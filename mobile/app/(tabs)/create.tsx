import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { Alert, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button, Input, Screen } from '../../src/components/ui';
import { FileUp, Link2, Route } from '../../src/components/ui/icons';
import { SseStatusIndicator } from '../../src/components/trip';
import { useTheme } from '../../src/theme';
import {
  isSupportedSourceUrl,
  pickGpxFile,
  runCreateTrip,
  runUploadGpx,
} from '../../src/store/create-trip';
import { runAnalyze } from '../../src/store/mutations';
import { useAnalysisFollow } from '../../src/hooks/use-analysis-follow';
import { useTripStore } from '../../src/store/trip-store';
import type { MutationFailure } from '../../src/store/gating';

type CreateErrorKey =
  | 'create.errors.offline'
  | 'create.errors.invalidUrl'
  | 'create.errors.invalidGpx'
  | 'create.errors.conflict'
  | 'create.errors.network'
  | 'create.errors.generic';

// Map a normalized mutation failure to a create-screen message key. A rejected
// or unsupported URL comes back as 422/404 (validation / not_found); everything
// else keeps its transversal wording.
function failureMessageKey(reason: MutationFailure): CreateErrorKey {
  switch (reason) {
    case 'offline':
      return 'create.errors.offline';
    case 'validation':
    case 'not_found':
      return 'create.errors.invalidUrl';
    case 'conflict':
      return 'create.errors.conflict';
    case 'network':
      return 'create.errors.network';
    default:
      return 'create.errors.generic';
  }
}

// GPX upload variant: a rejected file surfaces as 400 (missing/invalid extension
// → 'error') or 422 (empty / no track points → 'validation'); both mean "bad GPX"
// rather than a bad link. Other reasons keep the transversal wording.
function gpxFailureMessageKey(reason: MutationFailure): CreateErrorKey {
  switch (reason) {
    case 'offline':
      return 'create.errors.offline';
    case 'validation':
    case 'error':
      return 'create.errors.invalidGpx';
    case 'network':
      return 'create.errors.network';
    default:
      return 'create.errors.generic';
  }
}

// Create screen: paste a Komoot / Strava / RideWithGPS link (or open from a
// share deep link `?link=`) → POST /trips → follow the computation over SSE and
// launch / re-launch the enrichment analysis. Structured so a second creation
// method (GPX import / duplication, #1043) slots into the `form` phase without a
// rewrite: the paste-link block is one self-contained method among future ones.
export default function Create() {
  const { t } = useTranslation();
  const theme = useTheme();
  const router = useRouter();
  const params = useLocalSearchParams<{ link?: string }>();

  const [link, setLink] = useState('');
  const [touched, setTouched] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [createdId, setCreatedId] = useState<string | null>(null);
  const [launched, setLaunched] = useState(false);
  const [analyzing, setAnalyzing] = useState(false);
  // Bumped on every explicit analysis launch so the SSE follow resets its
  // progress readout for the new run.
  const [analyzeNonce, setAnalyzeNonce] = useState(0);

  const follow = useAnalysisFollow(createdId, analyzeNonce);

  // Prefill from a share deep link (biketripplanner://create?link=… or an App
  // Link routed here). Params can land after mount, so react to them.
  useEffect(() => {
    if (typeof params.link === 'string' && params.link.length > 0) {
      setLink(params.link);
      setCreatedId(null);
    }
  }, [params.link]);

  const trimmed = link.trim();
  const isValid = isSupportedSourceUrl(trimmed);
  const showError = touched && trimmed.length > 0 && !isValid;

  async function onSubmit(): Promise<void> {
    setTouched(true);
    if (!isValid) return;
    setSubmitting(true);
    const id = await runCreateTrip(trimmed, (reason) =>
      Alert.alert(t('create.createFailedTitle'), t(failureMessageKey(reason))),
    );
    setSubmitting(false);
    if (id) {
      setCreatedId(id);
      setLaunched(false);
    }
  }

  async function onImportGpx(): Promise<void> {
    const file = await pickGpxFile();
    if (!file) return; // user cancelled
    setUploading(true);
    const id = await runUploadGpx(file, (reason) =>
      Alert.alert(t('create.createFailedTitle'), t(gpxFailureMessageKey(reason))),
    );
    setUploading(false);
    if (id) {
      setCreatedId(id);
      setLaunched(false);
    }
  }

  async function launchAnalysis(id: string): Promise<void> {
    setAnalyzing(true);
    setAnalyzeNonce((n) => n + 1);
    const ok = await runAnalyze(id, useTripStore.getState(), (reason) =>
      Alert.alert(t('create.analysisFailedTitle'), t(failureMessageKey(reason))),
    );
    setAnalyzing(false);
    if (ok) setLaunched(true);
  }

  function reset(): void {
    setCreatedId(null);
    setLink('');
    setTouched(false);
    setLaunched(false);
  }

  const title = (
    <View style={{ gap: theme.spacing.sm }}>
      <Route color={theme.colors.brand} size={32} />
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.serif,
          fontSize: 26,
        }}
      >
        {t('create.title')}
      </Text>
      <Text
        style={{
          color: theme.colors.mutedForeground,
          fontFamily: theme.fonts.sans,
          fontSize: 15,
        }}
      >
        {t('create.subtitle')}
      </Text>
    </View>
  );

  const sectionLabel = (text: string) => (
    <Text
      style={{
        color: theme.colors.mutedForeground,
        fontFamily: theme.fonts.sansSemibold,
        fontSize: 12,
        letterSpacing: 0.8,
        textTransform: 'uppercase',
      }}
    >
      {text}
    </Text>
  );

  if (createdId) {
    return (
      <Screen scroll edges={['top', 'left', 'right']} style={{ gap: theme.spacing.lg }}>
        {title}
        <View style={{ gap: theme.spacing.md }}>
          <Text
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.sansSemibold,
              fontSize: 18,
            }}
          >
            {t('create.createdTitle')}
          </Text>
          <SseStatusIndicator computing={follow.computing} />
          {follow.computing && follow.total > 0 ? (
            <Text
              style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sans }}
            >
              {t('create.progress', { completed: follow.completed, total: follow.total })}
            </Text>
          ) : null}
          {follow.done ? (
            <Text style={{ color: theme.colors.foreground, fontFamily: theme.fonts.sansMedium }}>
              {t('create.analysisDone')}
            </Text>
          ) : null}
          {follow.failed ? (
            <Text style={{ color: theme.colors.destructive, fontFamily: theme.fonts.sansMedium }}>
              {t('create.analysisFailed')}
            </Text>
          ) : null}
          <Button
            label={launched ? t('create.relaunchAnalysis') : t('create.launchAnalysis')}
            onPress={() => void launchAnalysis(createdId)}
            loading={analyzing}
            fullWidth
          />
          <Button
            label={t('create.openRoadbook')}
            variant="secondary"
            onPress={() =>
              router.push({ pathname: '/trip/[id]', params: { id: createdId } })
            }
            fullWidth
          />
          <Button label={t('create.newTrip')} variant="ghost" onPress={reset} fullWidth />
        </View>
      </Screen>
    );
  }

  return (
    <Screen scroll edges={['top', 'left', 'right']} style={{ gap: theme.spacing.xl }}>
      {title}
      <View style={{ gap: theme.spacing.md }}>
        {sectionLabel(t('create.linkSection'))}
        <Input
          value={link}
          onChangeText={setLink}
          onBlur={() => setTouched(true)}
          placeholder={t('create.linkPlaceholder')}
          leadingIcon={<Link2 color={theme.colors.mutedForeground} size={18} />}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="url"
          inputMode="url"
          error={showError ? t('create.errors.invalidUrl') : undefined}
        />
        <Button
          label={submitting ? t('create.submitting') : t('create.submit')}
          onPress={() => void onSubmit()}
          loading={submitting}
          disabled={!isValid || uploading}
          fullWidth
        />
      </View>

      <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}>
        <View style={{ flex: 1, height: 1, backgroundColor: theme.colors.border }} />
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 13,
          }}
        >
          {t('create.or')}
        </Text>
        <View style={{ flex: 1, height: 1, backgroundColor: theme.colors.border }} />
      </View>

      <View style={{ gap: theme.spacing.md }}>
        {sectionLabel(t('create.gpxTitle'))}
        <Button
          label={uploading ? t('create.gpxUploading') : t('create.gpxImport')}
          onPress={() => void onImportGpx()}
          loading={uploading}
          disabled={submitting}
          variant="outlineForest"
          icon={<FileUp color={theme.colors.forestText} size={18} />}
          fullWidth
        />
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 13,
            textAlign: 'center',
          }}
        >
          {t('create.gpxHint')}
        </Text>
      </View>
    </Screen>
  );
}
