# ADR-054: Mobile Design System — Tokens Mirrored from Web

- **Status:** Accepted
- **Date:** 2026-08-13
- **Depends on:** ADR-053 (Mobile Strategy — Dedicated Native App)

## Context and Problem Statement

The native app (ADR-053) carries its own UI layer — React Native primitives, not
the web's React DOM + Tailwind + shadcn components. Sharing happens through
`@btp/core` (types + domain logic), not through rendered components. That leaves
an open question the web already answered: how does the mobile surface look, and
how does it stay visually coherent with the web?

The web's visual language is a set of CSS custom properties in
`pwa/src/app/globals.css`: a warm-paper / ink-charcoal / amber-accent palette
(`--brand #a8561a`, `--surface #faf7f0`, `--ink #1a1814`), a spacing and radius
scale, and three Google fonts (**Fraunces** serif, **Inter Tight** sans,
**JetBrains Mono** mono). Dark mode flips the same semantic tokens under a
`.dark` class (`--brand #e08040`, `--surface #1a1814`, `--ink #f5f0e8`), keeping
contrast-safe fill colours distinct from the brighter accent.

React Native has no CSS custom properties, no `.dark` selector, and no
`next/font`. We need a mobile design system that reproduces the same visual
identity without copy-pasting hex values ad hoc across screens (which drifts the
moment the web palette changes).

## Decision

Ship a **single mobile theme module** that mirrors the web tokens, rather than a
free-form per-screen styling approach.

- **Tokens mirror the web palette.** The mobile theme exposes the same semantic
  names the web uses (`brand`, `brandHover`, `brandFill`, `surface`, `ink`,
  `background`, `foreground`, `muted`, `accentBrand`, plus the spacing and radius
  scales) with the same values. The web `globals.css` remains the source of
  truth for the palette; the mobile module is a hand-maintained transcription of
  it (the two are small and change rarely). Components reference the theme, never
  raw hex.
- **Dark mode follows the OS colour scheme.** The theme is a light/dark pair
  selected at runtime via React Native's `useColorScheme()`, reproducing the
  web's light/dark token flip. Fill colours (`brandFill`) stay distinct from the
  accent (`brand`) in both schemes for the same WCAG-contrast reason documented
  in `globals.css` (white-on-accent fails at 2.86:1 in dark mode; the fill uses
  the darker `#a8561a`).
- **Fonts loaded natively.** Fraunces, Inter Tight, and JetBrains Mono are
  bundled and loaded through `expo-font` (rather than `next/font`), so headings,
  body, and monospaced numerics match the web typographically.

## Consequences

- The palette lives in two places (web CSS + mobile theme). This is deliberate:
  no build-time token pipeline is worth maintaining for two consumers and a
  palette that changes a few times a year. A palette edit on the web is a
  one-line mirror on mobile; ADR-055's shared-`@btp/core` boundary explicitly
  covers types and domain logic, **not** presentation, so styling is expected to
  diverge in mechanism while converging in values.
- Dark mode is automatic (OS-driven) with no in-app toggle initially, matching
  the field-use context (a rider on a trail wants the system setting honoured).
- New screens get visual coherence for free by consuming the theme; a screen that
  hardcodes a colour is a review smell, the mobile equivalent of bypassing a
  Tailwind token on the web.

## References

- [ADR-053](adr-053-mobile-strategy-native-app.md) — Mobile strategy (native app)
- `pwa/src/app/globals.css` — web design-token source of truth
- [Expo Font](https://docs.expo.dev/versions/latest/sdk/font/)
