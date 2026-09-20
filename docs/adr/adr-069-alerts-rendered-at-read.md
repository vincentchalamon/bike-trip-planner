# ADR-069 — Alerts are rendered at read time, not at compute time

**Status:** accepted
**Date:** 2026-09-20
**Builds on** [ADR-068](adr-068-enrichment-durability-by-group.md) (an enrichment now survives
the next edit) and [ADR-063](adr-063-transport-agnostic-authorization.md) (the account, not a
header, decides the language).

## Context

Every one of the twenty alert producers called `$translator->trans(...)` and then persisted
the result. The stored row therefore carried whatever language the trip happened to be
computed in.

ADR-068 made that durable, which is what turned an annoyance into a defect: the row now
survives, so the language survives with it. An account that switches to English keeps reading
French alerts until something recomputes them — and nothing has to. Only a structural edit
re-dispatches the producers, so a trip nobody edits stays in the wrong language for good.

It also blocks the programme's endpoint. An MCP client answers in the language of its
conversation, which this server cannot know. Handing it a French sentence is strictly worse
than handing it `SUNSET_ARRIVAL_AFTER_TWILIGHT` and `{arrivalTime, sunsetTime}`.

## Decision

### The row holds a key and its arguments, never prose

`messageKey` plus `parameters`. `App\Alert\AlertRenderer` turns the pair into a sentence when
someone reads it. The action label follows the same rule: `labelKey` is stored, `label` is
rendered.

**The key is carried, not derived from the code.** `AlertCode` names the rule variant
(ADR-068), and one variant does not map to one sentence: a cultural POI reads differently with
and without a name, and so does a public holiday. Two codes therefore own two keys each.
Deriving the key from the code would have forced either a code per phrasing — inflating an
enum the frontend keys dismissal on — or per-code branching in the renderer.

### The parameters are raw, and say how they want to be read

Fifteen of them were passed through `DistanceFormatter` or `DecimalFormatter` before being
interpolated, so the *formatting* was frozen as firmly as the language: a stored `"8,3"` reads
wrong in an English sentence, and a stored `"5 km"` is not a number any client can use.

`parameters` now holds the raw value — metres, a percentage, a count — and `parameterFormats`
names how to render each. A placeholder absent from that map is interpolated as-is.

Two of the formats are not number formatting at all. Surface names (`gravel`, `tracktype=grade4`)
and POI categories were themselves translations *inside* a parameter, so storing them rendered
would have left a French word inside an English sentence. They travel as raw OSM values and the
renderer names them, falling back rather than leaking a raw tag.

This is what makes the contract better for an API client than the old one: it receives the
metres, not a string it would have to parse back.

### The stage numbers are derived, never stored

`%stage%`, `%from%` and `%to%` are injected by the renderer from the owning stage, for exactly
the reason ADR-068 refused to store `dayNumber` at all: every structural edit renumbers it, so
a frozen copy drifts.

`%from%`/`%to%` need no stored reference either. A continuity gap sits on the earlier of the
two stages it spans, so the pair is always consecutive and both derive from the owner alone.

### Whose language

The account's, never `Accept-Language` — ADR-063 settled that, and an API client sends no
header anyway. `App\Alert\ReaderLocale` resolves the signed-in user and falls back to the
trip's stored locale.

That fallback carries the anonymous case. Nobody is signed in on `/s/{shortCode}`, so the
share page renders in the language of the trip's owner, who is the only person who chose
anything about it.

On the Mercure side the trip locale is not a fallback but the right answer: an anonymous
visitor receives no SSE, so the only audience for a published payload is the owner whose
account locale the trip already carries.

### GET/SSE parity survives, differently

ADR-068 held that the array handed to the database is the array put on the wire. That can no
longer be literally true, since the wire now carries a rendered `message` the database does
not hold.

What is preserved is the property that mattered: **one builder, two consumers**. The producer
builds the source row once, hands it to the database, and hands the same row to the renderer
for the wire. The two cannot carry different facts; they carry the same facts, one of them
with a sentence derived from them.

## What stays at compute time

The distinction is *rendering* versus *acquisition*. Rendering is deferred. Acquisition — a
language handed to a third party, which then returns data in it — cannot be, because the third
party is not called at read time.

Three cases remain, and are deliberately out of scope:

- **Holiday names** (`%holiday%`) come from Yasumi, called with the trip locale.
- **`poiName`** falls back to a localised category when OpenStreetMap has no name.
- **Reverse-geocoded stage labels** and DataTourisme descriptions, which ADR-063 already
  pinned to the account locale at write time.

The first two are the same defect in a smaller place: a French holiday name can still land in
an English sentence. Yasumi is a local library, so it *could* move to read time; the POI
fallback would need `poiName` to become nullable on the wire, which is a client change. Both
are follow-ups, not silent omissions.

## Consequences

The producers no longer depend on `TranslatorInterface`, `DistanceFormatter` or
`DecimalFormatter` — eight analyzers and twelve handlers lost the dependency outright, and the
`locale` entry of the analysis context with it.

The tests moved with the behaviour. Assertions that a producer emitted a particular sentence
became assertions that it emitted a particular key and particular arguments; the sentences are
asserted once, in `AlertRendererTest`, against the real catalogue. The `usesLocaleFromContext`
tests, which asserted that an analyzer passed a locale to a translator, describe something that
no longer happens and are gone.

`message` and `label` are unchanged on the wire, so no client had to change: the contract is
additive, and `messageKey`/`parameters`/`parameterFormats`/`labelKey` are there for whoever
wants them.

## Alternatives considered

**Let the clients translate.** It would remove the server round trip for a language change
entirely. Rejected: it means shipping the 137-key catalogue to three clients and keeping them
in step, for a gain the server already delivers — and the MCP client, which has no catalogue,
would be the one left out.

**Keep the formatting at compute time, defer only the language.** Much smaller. Rejected
because it is half a fix: an English reader would get "8,3 %" inside an English sentence,
which is the same class of defect this ADR exists to close.

**A `format` object per parameter** (`{value, format, precision}`) instead of two parallel
maps. Rejected: it makes `parameters` non-scalar, so the client that wants the raw number has
to reach through a wrapper — and the raw number is the point.

**Derive the key from the code.** Rejected above: the relation is one-to-many, and forcing it
to be one-to-one would either inflate `AlertCode` past what it means (a rule variant, not a
phrasing) or move per-code branching into the renderer.
