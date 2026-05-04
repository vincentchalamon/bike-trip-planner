/**
 * Lightweight parser/formatter for OSM-style `opening_hours` strings.
 *
 * The full grammar is huge (https://wiki.openstreetmap.org/wiki/Key:opening_hours)
 * — for the popover we only need a "human readable status now" line, e.g.
 * "Ouvert jusqu'a 18h" or "Ferme, ouvre demain a 9h". This module covers the
 * common patterns we encounter on cultural POIs (museums / monuments) without
 * pulling in a multi-kB library:
 *
 *   - `24/7`
 *   - `Mo-Fr 09:00-18:00`
 *   - `Mo-Fr 09:00-18:00; Sa,Su 10:00-17:00`
 *   - `Mo,We,Fr 09:00-12:00,14:00-18:00`
 *   - free-form fallbacks (returned verbatim)
 *
 * Issue #398 - sprint 26.
 */

const DAY_INDEX: Record<string, number> = {
  // OSM uses 2-letter codes, Monday = 0
  Mo: 0,
  Tu: 1,
  We: 2,
  Th: 3,
  Fr: 4,
  Sa: 5,
  Su: 6,
};

const DAY_FROM_JS = [6, 0, 1, 2, 3, 4, 5] as const; // JS Date.getDay() -> OSM index

interface ParsedRange {
  /** Set of OSM day indices (Mo=0…Su=6) covered by this range. */
  days: ReadonlySet<number>;
  /** Time intervals as { start, end } in minutes from 00:00. */
  intervals: ReadonlyArray<{ start: number; end: number }>;
}

interface OpeningStatus {
  isOpen: boolean;
  /** Human-readable label, locale-dependent. */
  label: string;
}

const HOURS_24_7 = new Set(["24/7", "Mo-Su 00:00-24:00"]);

const TIME_INTERVAL_RE = /^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/;
const TIME_TOKEN_RE = /\d{1,2}:\d{2}/;

function parseDayToken(token: string): number[] {
  const trimmed = token.trim();
  if (trimmed.includes("-")) {
    const [from, to] = trimmed.split("-").map((p) => p.trim());
    const fromIdx = from !== undefined ? DAY_INDEX[from] : undefined;
    const toIdx = to !== undefined ? DAY_INDEX[to] : undefined;
    if (fromIdx === undefined || toIdx === undefined) return [];
    const out: number[] = [];
    let i = fromIdx;
    while (true) {
      out.push(i);
      if (i === toIdx) break;
      i = (i + 1) % 7;
      if (out.length > 7) break;
    }
    return out;
  }
  const idx = DAY_INDEX[trimmed];
  return idx === undefined ? [] : [idx];
}

function parseTimeInterval(raw: string): { start: number; end: number } | null {
  const match = raw.trim().match(TIME_INTERVAL_RE);
  if (!match) return null;
  const [, sh, sm, eh, em] = match;
  const start = Number(sh) * 60 + Number(sm);
  let end = Number(eh) * 60 + Number(em);
  // 24:00 represents end-of-day - keep it numerically as 1440.
  if (end === 0 && raw.includes("24:00")) end = 1440;
  if (Number.isNaN(start) || Number.isNaN(end)) return null;
  return { start, end };
}

/**
 * Parses a single `<days> <times>` rule into a {@link ParsedRange}, or
 * returns null when the rule is too exotic to be statically interpreted.
 */
function parseRule(rule: string): ParsedRange | null {
  const trimmed = rule.trim();
  if (!trimmed) return null;

  // Locate the boundary between day part and time part. Time tokens always
  // contain ":" (HH:MM) - the first token containing ":" starts the times.
  const tokens = trimmed.split(/\s+/);
  const firstTimeIdx = tokens.findIndex((tok) => TIME_TOKEN_RE.test(tok));
  if (firstTimeIdx === -1) return null;

  const dayPart =
    firstTimeIdx === 0 ? "Mo-Su" : tokens.slice(0, firstTimeIdx).join(" ");
  const timePart = tokens.slice(firstTimeIdx).join(" ");

  const days = new Set<number>();
  for (const token of dayPart.split(",")) {
    for (const idx of parseDayToken(token)) days.add(idx);
  }
  if (days.size === 0) return null;

  const intervals: { start: number; end: number }[] = [];
  for (const tok of timePart.split(",")) {
    const interval = parseTimeInterval(tok);
    if (interval) intervals.push(interval);
  }
  if (intervals.length === 0) return null;

  return { days, intervals };
}

/**
 * Parses an OSM `opening_hours` string into a list of rules. Returns an empty
 * array when nothing could be statically interpreted.
 */
export function parseOpeningHours(raw: string): ParsedRange[] {
  if (HOURS_24_7.has(raw.trim())) {
    return [
      {
        days: new Set([0, 1, 2, 3, 4, 5, 6]),
        intervals: [{ start: 0, end: 1440 }],
      },
    ];
  }
  const rules: ParsedRange[] = [];
  for (const rule of raw.split(";")) {
    const parsed = parseRule(rule);
    if (parsed) rules.push(parsed);
  }
  return rules;
}

interface I18n {
  open24h: string;
  openUntil: (time: string) => string;
  closedReopensToday: (time: string) => string;
  closedReopensTomorrow: (time: string) => string;
  closedReopensIn: (days: number, time: string) => string;
  closed: string;
}

const FR: I18n = {
  open24h: "Ouvert 24h/24",
  openUntil: (t) => `Ouvert jusqu'à ${t}`,
  closedReopensToday: (t) => `Fermé, ouvre à ${t}`,
  closedReopensTomorrow: (t) => `Fermé, ouvre demain à ${t}`,
  closedReopensIn: (d, t) => `Fermé, ouvre dans ${d} jours à ${t}`,
  closed: "Fermé",
};

const EN: I18n = {
  open24h: "Open 24/7",
  openUntil: (t) => `Open until ${t}`,
  closedReopensToday: (t) => `Closed, opens at ${t}`,
  closedReopensTomorrow: (t) => `Closed, opens tomorrow at ${t}`,
  closedReopensIn: (d, t) => `Closed, opens in ${d} days at ${t}`,
  closed: "Closed",
};

function pickI18n(locale: string): I18n {
  return locale.toLowerCase().startsWith("fr") ? FR : EN;
}

function formatTime(minutes: number, locale: string): string {
  const h = Math.floor(minutes / 60) % 24;
  const m = minutes % 60;
  if (locale.toLowerCase().startsWith("fr")) {
    return m === 0 ? `${h}h` : `${h}h${String(m).padStart(2, "0")}`;
  }
  const period = h >= 12 ? "PM" : "AM";
  const hour12 = ((h + 11) % 12) + 1;
  return m === 0
    ? `${hour12} ${period}`
    : `${hour12}:${String(m).padStart(2, "0")} ${period}`;
}

/**
 * Returns a localized "open / closed" status for the given OSM
 * `opening_hours` string at the supplied reference date.
 *
 * Falls back to echoing the raw string when parsing fails, so the user still
 * sees something useful even on exotic formats.
 */
export function formatOpeningHoursStatus(
  raw: string,
  locale: string,
  now: Date,
): OpeningStatus {
  const rules = parseOpeningHours(raw);
  if (rules.length === 0) {
    return { isOpen: false, label: raw };
  }

  const i18n = pickI18n(locale);
  if (HOURS_24_7.has(raw.trim())) {
    return { isOpen: true, label: i18n.open24h };
  }

  const todayJs = now.getDay();
  const todayOsm = DAY_FROM_JS[todayJs] ?? 0;
  const nowMinutes = now.getHours() * 60 + now.getMinutes();

  // Open right now?
  for (const rule of rules) {
    if (!rule.days.has(todayOsm)) continue;
    for (const interval of rule.intervals) {
      if (nowMinutes >= interval.start && nowMinutes < interval.end) {
        return {
          isOpen: true,
          label: i18n.openUntil(formatTime(interval.end, locale)),
        };
      }
    }
  }

  // Find next opening - scan up to 7 days ahead.
  for (let offset = 0; offset < 8; offset++) {
    const dayOsm = (todayOsm + offset) % 7;
    let earliest: number | null = null;
    for (const rule of rules) {
      if (!rule.days.has(dayOsm)) continue;
      for (const interval of rule.intervals) {
        if (offset === 0 && interval.start <= nowMinutes) continue;
        if (earliest === null || interval.start < earliest) {
          earliest = interval.start;
        }
      }
    }
    if (earliest !== null) {
      const time = formatTime(earliest, locale);
      if (offset === 0) {
        return { isOpen: false, label: i18n.closedReopensToday(time) };
      }
      if (offset === 1) {
        return { isOpen: false, label: i18n.closedReopensTomorrow(time) };
      }
      return { isOpen: false, label: i18n.closedReopensIn(offset, time) };
    }
  }

  return { isOpen: false, label: i18n.closed };
}
