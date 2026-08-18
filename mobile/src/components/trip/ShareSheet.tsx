import { useCallback, useEffect, useRef, useState } from 'react';
import { ScrollView, Share, StyleSheet, Text, View } from 'react-native';
import * as Clipboard from 'expo-clipboard';
import { useTranslation } from 'react-i18next';
import { Button, Sheet } from '../ui';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';
import {
  buildShareUrl,
  createTripShare,
  getTripShare,
  revokeTripShare,
} from '../../api/trips';
import { buildTripText, computeTripTotals } from '../../lib/share';
import { captureAndShareInfographic } from '../../lib/share-image';
import { ShareInfographic } from './ShareInfographic';
import { type View as RNView } from 'react-native';

interface ShareSheetProps {
  visible: boolean;
  onClose: () => void;
  tripId: string;
}

// Share panel (#1048): create/revoke the public `/s/<code>` link, share the
// infographic PNG (captured off-screen) and the formatted text (budget + link)
// via clipboard / native share. Mirrors the web ShareModal content.
export function ShareSheet({ visible, onClose, tripId }: ShareSheetProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const title = useTripStore((s) => s.title) ?? '';
  const stages = useTripStore((s) => s.stages);
  const startDate = useTripStore((s) => s.startDate);
  const endDate = useTripStore((s) => s.endDate);
  const sourceUrl = useTripStore((s) => s.sourceUrl) ?? '';

  const [shareUrl, setShareUrl] = useState<string | null>(null);
  const [hasFetched, setHasFetched] = useState(false);
  const [busy, setBusy] = useState(false);
  const [linkCopied, setLinkCopied] = useState(false);
  const [textCopied, setTextCopied] = useState(false);

  const infographicRef = useRef<RNView>(null);

  // Fetch the active share once, the first time the sheet opens.
  useEffect(() => {
    if (!visible || hasFetched) return;
    let cancelled = false;
    setBusy(true);
    getTripShare(tripId)
      .then((result) => {
        if (cancelled || !result?.shortCode) return;
        setShareUrl(buildShareUrl(result.shortCode));
      })
      .finally(() => {
        if (!cancelled) {
          setBusy(false);
          setHasFetched(true);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [visible, hasFetched, tripId]);

  const handleCreateLink = useCallback(async () => {
    setBusy(true);
    const result = await createTripShare(tripId);
    if (result?.shortCode) {
      setShareUrl(buildShareUrl(result.shortCode));
    }
    setBusy(false);
  }, [tripId]);

  const handleRevokeLink = useCallback(async () => {
    setBusy(true);
    const ok = await revokeTripShare(tripId);
    if (ok) setShareUrl(null);
    setBusy(false);
  }, [tripId]);

  const handleCopyLink = useCallback(async () => {
    if (!shareUrl) return;
    await Clipboard.setStringAsync(shareUrl);
    setLinkCopied(true);
    setTimeout(() => setLinkCopied(false), 2000);
  }, [shareUrl]);

  const buildText = useCallback(() => {
    const totals = computeTripTotals(stages);
    return buildTripText({
      title,
      totalDistance: stages.length > 0 ? totals.totalDistance : null,
      totalElevation: stages.length > 0 ? totals.totalElevation : null,
      totalElevationLoss: totals.totalElevationLoss,
      sourceUrl,
      stages,
      startDate,
      shareUrl,
      labels: {
        totalDistance: t('share.totalDistance'),
        totalElevation: t('share.totalElevation'),
        viewOnline: t('share.viewOnline'),
      },
    });
  }, [title, stages, sourceUrl, startDate, shareUrl, t]);

  const handleCopyText = useCallback(async () => {
    await Clipboard.setStringAsync(buildText());
    setTextCopied(true);
    setTimeout(() => setTextCopied(false), 2000);
  }, [buildText]);

  const handleShareText = useCallback(async () => {
    await Share.share({ message: buildText() });
  }, [buildText]);

  const handleShareImage = useCallback(async () => {
    setBusy(true);
    try {
      await captureAndShareInfographic(infographicRef, title);
    } finally {
      setBusy(false);
    }
  }, [title]);

  return (
    <Sheet visible={visible} onClose={onClose} title={t('share.title')}>
      <ScrollView style={styles.scroll}>
        {/* Public link */}
        <Text style={[styles.heading, { color: theme.colors.foreground }]}>
          {t('share.linkTitle')}
        </Text>
        {shareUrl ? (
          <View style={styles.section}>
            <Text
              testID="share-link-text"
              selectable
              style={[styles.link, { color: theme.colors.brand }]}
            >
              {shareUrl}
            </Text>
            <Text style={[styles.note, { color: theme.colors.mutedForeground }]}>
              {t('share.linkReadOnlyNote')}
            </Text>
            <View style={styles.row}>
              <Button
                label={linkCopied ? t('share.linkCopied') : t('share.copyLink')}
                variant="secondary"
                size="sm"
                onPress={() => void handleCopyLink()}
              />
              <Button
                label={t('share.revokeLink')}
                variant="destructive"
                size="sm"
                loading={busy}
                onPress={() => void handleRevokeLink()}
              />
            </View>
          </View>
        ) : (
          <Button
            label={t('share.createLink')}
            variant="secondary"
            size="sm"
            loading={busy}
            onPress={() => void handleCreateLink()}
          />
        )}

        {/* Infographic */}
        <Text style={[styles.heading, { color: theme.colors.foreground }]}>
          {t('share.infographicTitle')}
        </Text>
        <Button
          label={t('share.shareImage')}
          variant="secondary"
          size="sm"
          loading={busy}
          onPress={() => void handleShareImage()}
        />

        {/* Formatted text */}
        <Text style={[styles.heading, { color: theme.colors.foreground }]}>
          {t('share.textTitle')}
        </Text>
        <View style={styles.row}>
          <Button
            label={textCopied ? t('share.textCopied') : t('share.copyText')}
            variant="secondary"
            size="sm"
            onPress={() => void handleCopyText()}
          />
          <Button
            label={t('share.shareText')}
            variant="secondary"
            size="sm"
            onPress={() => void handleShareText()}
          />
        </View>
      </ScrollView>

      {/* Off-screen infographic captured to PNG by handleShareImage. */}
      <View style={styles.offscreen} pointerEvents="none">
        <ShareInfographic
          ref={infographicRef}
          title={title}
          stages={stages}
          startDate={startDate}
          endDate={endDate}
          labels={{
            distance: t('share.statDistance'),
            elevation: t('share.statElevation'),
            dates: t('share.statDates'),
            budget: t('share.statBudget'),
            difficulty: {
              label: t('share.statDifficulty'),
              easy: t('share.difficultyEasy'),
              medium: t('share.difficultyMedium'),
              hard: t('share.difficultyHard'),
            },
            powered: t('share.poweredBy'),
          }}
        />
      </View>
    </Sheet>
  );
}

const styles = StyleSheet.create({
  scroll: { maxHeight: 420 },
  heading: { fontSize: 14, fontWeight: '600', marginTop: 16, marginBottom: 8 },
  section: { gap: 8 },
  link: { fontSize: 14, textDecorationLine: 'underline' },
  note: { fontSize: 12 },
  row: { flexDirection: 'row', gap: 8, flexWrap: 'wrap' },
  offscreen: { position: 'absolute', left: -10000, top: 0 },
});
