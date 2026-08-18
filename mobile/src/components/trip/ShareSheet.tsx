import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import * as Clipboard from 'expo-clipboard';
import { useTranslation } from 'react-i18next';
import { Button, Sheet } from '../ui';
import {
  Check,
  ChevronRight,
  FileText,
  ImageIcon,
  Link2,
} from '../ui/icons';
import { useTheme } from '../../theme';
import type { Theme } from '../../theme';
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
// via clipboard. Restyled to the Spike-UX mockup: read-only link field + accent
// copy, then a stack of icon "send another way" option rows.
export function ShareSheet({ visible, onClose, tripId }: ShareSheetProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const s = styles(theme);
  const title = useTripStore((st) => st.title) ?? '';
  const stages = useTripStore((st) => st.stages);
  const startDate = useTripStore((st) => st.startDate);
  const endDate = useTripStore((st) => st.endDate);
  const sourceUrl = useTripStore((st) => st.sourceUrl) ?? '';

  const [shareUrl, setShareUrl] = useState<string | null>(null);
  const [hasFetched, setHasFetched] = useState(false);
  const [busy, setBusy] = useState(false);
  // Image capture has its own busy flag so its spinner doesn't light up the link
  // buttons. The off-screen infographic (expensive useMemo pipeline) is mounted
  // only for the duration of a capture (`capturing`) — not on open / every SSE
  // update — so the sheet stays snappy.
  const [imageBusy, setImageBusy] = useState(false);
  const [capturing, setCapturing] = useState(false);
  const [linkCopied, setLinkCopied] = useState(false);
  const [textCopied, setTextCopied] = useState(false);

  const infographicRef = useRef<RNView>(null);
  // Guards the one-shot capture: set on press, consumed by the off-screen view's
  // onLayout so a screenshot fires exactly once, only after real layout.
  const captureArmedRef = useRef(false);

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
    const base = buildTripText({
      title,
      totalDistance: stages.length > 0 ? totals.totalDistance : null,
      totalElevation: stages.length > 0 ? totals.totalElevation : null,
      totalElevationLoss: totals.totalElevationLoss,
      sourceUrl,
      stages,
      startDate,
      labels: {
        totalDistance: t('share.totalDistance'),
        totalElevation: t('share.totalElevation'),
      },
    });
    // The public link is appended here (not in the shared core builder), so the
    // read-only `/s/<code>` URL is included only once a link exists (mirrors the
    // web ShareModal's fullText).
    return shareUrl ? `${base}\n\n${t('share.viewOnline')} : ${shareUrl}` : base;
  }, [title, stages, sourceUrl, startDate, shareUrl, t]);

  const handleCopyText = useCallback(async () => {
    await Clipboard.setStringAsync(buildText());
    setTextCopied(true);
    setTimeout(() => setTextCopied(false), 2000);
  }, [buildText]);

  // Mount the off-screen infographic; the capture fires from its onLayout (see
  // runCapture) so we never race layout on a slow device / heavier trip.
  const handleShareImage = useCallback(() => {
    setImageBusy(true);
    captureArmedRef.current = true;
    setCapturing(true);
  }, []);

  const runCapture = useCallback(async () => {
    if (!captureArmedRef.current) return;
    captureArmedRef.current = false;
    try {
      await captureAndShareInfographic(infographicRef, title);
    } finally {
      setCapturing(false);
      setImageBusy(false);
    }
  }, [title]);

  return (
    <Sheet visible={visible} onClose={onClose} title={t('share.title')}>
      <Text style={s.subtitle}>{t('share.subtitle')}</Text>
      <ScrollView style={s.scroll} showsVerticalScrollIndicator={false}>
        {/* Read-only public link */}
        <Text style={s.section}>{t('share.sectionLink')}</Text>
        <View style={s.linkRow}>
          <View style={s.field}>
            {shareUrl ? (
              <Text
                testID="share-link-text"
                selectable
                numberOfLines={1}
                style={s.fieldText}
              >
                {shareUrl}
              </Text>
            ) : (
              <Text numberOfLines={1} style={s.fieldPlaceholder}>
                {t('share.linkPlaceholder')}
              </Text>
            )}
          </View>
          {shareUrl ? (
            <Button
              label={linkCopied ? t('share.linkCopied') : t('share.copyLink')}
              variant="primary"
              size="md"
              onPress={() => void handleCopyLink()}
            />
          ) : (
            <Button
              label={t('share.createLink')}
              variant="primary"
              size="md"
              loading={busy}
              onPress={() => void handleCreateLink()}
            />
          )}
        </View>
        {shareUrl ? (
          <View style={s.linkFooter}>
            <Text style={s.note}>{t('share.linkReadOnlyNote')}</Text>
            <Button
              label={t('share.revokeLink')}
              variant="ghost"
              size="sm"
              loading={busy}
              onPress={() => void handleRevokeLink()}
            />
          </View>
        ) : null}

        {/* Send another way */}
        <Text style={s.section}>{t('share.sectionOther')}</Text>
        <OptionRow
          theme={theme}
          icon={<Link2 size={20} color={theme.colors.brand} />}
          label={t('share.optLinkLabel')}
          description={t('share.optLinkDesc')}
          onPress={() =>
            void (shareUrl ? handleCopyLink() : handleCreateLink())
          }
        />
        <OptionRow
          theme={theme}
          icon={<ImageIcon size={20} color={theme.colors.brand} />}
          label={t('share.optImageLabel')}
          description={t('share.optImageDesc')}
          busy={imageBusy}
          onPress={handleShareImage}
        />
        <OptionRow
          theme={theme}
          icon={<FileText size={20} color={theme.colors.brand} />}
          label={t('share.optTextLabel')}
          description={t('share.optTextDesc')}
          done={textCopied}
          onPress={() => void handleCopyText()}
        />
      </ScrollView>

      {/* Off-screen infographic captured to PNG by handleShareImage. Mounted only
          while a capture is in progress; the capture fires from onLayout (once it
          has actually laid out) so it never races layout and never runs on idle
          SSE updates. */}
      {capturing && (
        <View
          style={s.offscreen}
          pointerEvents="none"
          onLayout={() => void runCapture()}
        >
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
      )}
    </Sheet>
  );
}

interface OptionRowProps {
  theme: Theme;
  icon: ReactNode;
  label: string;
  description: string;
  onPress: () => void;
  busy?: boolean;
  done?: boolean;
}

// One "send another way" row: square accent-tinted icon, label + description,
// trailing chevron (or a spinner while capturing / a check once copied).
function OptionRow({
  theme,
  icon,
  label,
  description,
  onPress,
  busy = false,
  done = false,
}: OptionRowProps) {
  const s = styles(theme);
  return (
    <Pressable
      accessibilityRole="button"
      onPress={onPress}
      style={({ pressed }) => [s.opt, pressed && s.optPressed]}
    >
      <View style={s.optIcon}>{icon}</View>
      <View style={s.optBody}>
        <Text style={s.optLabel}>{label}</Text>
        <Text style={s.optDesc}>{description}</Text>
      </View>
      {busy ? (
        <ActivityIndicator color={theme.colors.mutedForeground} />
      ) : done ? (
        <Check size={20} color={theme.colors.brand} />
      ) : (
        <ChevronRight size={20} color={theme.colors.mutedIcon} />
      )}
    </Pressable>
  );
}

const styles = (theme: Theme) =>
  StyleSheet.create({
    subtitle: {
      color: theme.colors.mutedForeground,
      fontFamily: theme.fonts.sans,
      fontSize: 14,
      marginBottom: theme.spacing.base,
    },
    scroll: { maxHeight: 460 },
    section: {
      color: theme.colors.mutedForeground,
      fontFamily: theme.fonts.sansSemibold,
      fontSize: 13,
      marginTop: theme.spacing.lg,
      marginBottom: theme.spacing.sm,
    },
    linkRow: { flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm },
    field: {
      flex: 1,
      justifyContent: 'center',
      height: 44,
      paddingHorizontal: theme.spacing.md,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: theme.colors.input,
      borderRadius: theme.radius.md,
      backgroundColor: theme.colors.surface,
    },
    fieldText: {
      color: theme.colors.foreground,
      fontFamily: theme.fonts.mono,
      fontSize: 13,
    },
    fieldPlaceholder: {
      color: theme.colors.mutedForeground,
      fontFamily: theme.fonts.sans,
      fontSize: 13,
    },
    linkFooter: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: theme.spacing.sm,
      marginTop: theme.spacing.sm,
    },
    note: {
      flex: 1,
      color: theme.colors.mutedForeground,
      fontFamily: theme.fonts.sans,
      fontSize: 12,
    },
    opt: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: theme.spacing.md,
      padding: theme.spacing.md,
      marginBottom: theme.spacing.sm,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: theme.colors.border,
      borderRadius: theme.radius.xl,
      backgroundColor: theme.colors.card,
    },
    optPressed: { backgroundColor: theme.colors.muted },
    optIcon: {
      alignItems: 'center',
      justifyContent: 'center',
      width: 40,
      height: 40,
      borderRadius: theme.radius.md,
      backgroundColor: theme.colors.brandLight,
    },
    optBody: { flex: 1 },
    optLabel: {
      color: theme.colors.foreground,
      fontFamily: theme.fonts.sansSemibold,
      fontSize: 15,
    },
    optDesc: {
      color: theme.colors.mutedForeground,
      fontFamily: theme.fonts.sans,
      fontSize: 13,
      marginTop: 2,
    },
    offscreen: { position: 'absolute', left: -10000, top: 0 },
  });
