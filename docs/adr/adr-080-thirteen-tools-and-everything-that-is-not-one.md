# ADR-080 — Thirteen tools, and everything that is not one

**Status:** accepted
**Date:** 2026-09-25
**Amends** [ADR-064](adr-064-mcp-server-as-third-api-client.md), whose exclusion list was
partial and argued case by case. This replaces it with a table of motives, so that a later
request to expose an operation is discussed against a reason rather than re-litigated.
**Continues** [ADR-063](adr-063-transport-agnostic-authorization.md),
[ADR-077](adr-077-a-creation-carries-its-own-key.md),
[ADR-078](adr-078-what-an-etag-promises.md) and
[ADR-079](adr-079-authorizing-an-agent-without-giving-it-a-session.md).

## Context

Fifty operations are routed. Thirteen are tools. This records which thirteen, why the other
thirty-seven are not, and what building the thirteen turned up that no amount of reading the
code would have.

The unit shipped in three parts: the four reads, the four writes that carry no body, and the
five that do. The order was not cosmetic — writing is the jump, because three contracts this
API had settled were expressed in HTTP headers, and `POST /mcp` has none.

## Decision

### 1. Thirteen tools, at the level of intent

| Tool | Operation behind it | Scope |
|---|---|---|
| `list_trips` | `GET /trips` | `trips:read` |
| `get_trip` | `GET /trips/{id}/detail` | `trips:read` |
| `get_stage` | `GET /trips/{tripId}/stages/{stageId}/detail` | `trips:read` |
| `search_places` | `GET /geocode/search` | `trips:read` |
| `create_trip` | `POST /trips` | `trips:write` |
| `update_trip_settings` | `PATCH /trips/{id}` | `trips:write` |
| `edit_stages` | five `Stage` operations | `trips:write` |
| `add_waypoint` | `POST /stages/{stageId}/poi-waypoint` | `trips:write` |
| `choose_accommodation` | `PATCH /stages/{stageId}/accommodation` | `trips:write` |
| `analyze_trip` | `POST /trips/{id}/analyze` | `trips:write` |
| `share_trip` | `POST /trips/{tripId}/share` | `trips:write` |
| `unshare_trip` | `DELETE /trips/{tripId}/share` | `trips:write` |
| `delete_trip` | `DELETE /trips/{id}` | `trips:write` |

A tool is never one-to-one with an operation. `edit_stages` is five operations, because they
are one concept — recutting the day split — wearing five URLs: same authorization expression,
same critical section, same version bump, same guards. A verb and a path are artefacts of REST
and mean nothing to a model.

### 2. What is not a tool, by motive

Every operation that is not exposed falls into exactly one of these. Adding a ninth motive is a
decision to be recorded here, not a line to slip into a table.

| Motive | Operations | Why |
|---|---|---|
| **The gesture has no textual equivalent** | `GET /trips/{id}/route`, `GET /s/{code}/route` | A polyline is an artefact of a map. Nothing caps a tool response, no `maxPoints` exists, and MCP arguments reach neither filters nor pagination: a 2 000 km trail is a context bomb answering no question. The same ground is served as distances, climbs and place names per day |
| **Binary or bulk payload** | `POST /trips/gpx-upload`, `GET /trips/{id}` as gpx/fit, `GET /stages/{id}/export`, `GET /s/{code}.gpx\|.fit`, `GET /users/me/export` | A `tools/call` result is one JSON document; it carries no bytes. Creation is **by source URL only**, an assumed functional restriction. `share_trip` answers with a public address whose `/s/{code}.gpx` needs no token: the agent hands over the link, it does not carry the file |
| **Needs a live device** | `POST /trips/{id}/nearby-pois`, `POST\|DELETE /users/me/device-tokens` | A GPS fix at an instant; ADR-048 built the deterministic offline search for the opposite use. A push token is scoped to a device an agent does not have |
| **Needs a browser and a mailbox** | all `/auth/*`, `/users/me/email-change*`, `/oauth/pending-authorizations/*`, `POST /access-requests`, `GET /access-requests/verify` | Magic link, cookie, consent: this is the path by which a human authorizes the agent. Opening it to the agent would let it authorize itself |
| **Irreversible, at the level of identity** | `DELETE /users/me` | Immediate irreversible anonymisation. Never an agent tool, whatever the confirmation |
| **Already covered by another tool** | `get_trip_alerts`, `export_trip`, `GET /trips/{id}`, the five `/s/{code}*` reads, `POST /trips/{id}/duplicate` | Alerts live *inside* `get_trip`; a separate tool would be a second rendering path for the same facts with no endpoint to feed it. `GET /trips/{id}` returns `{id, computationStatus, isLocked}`. The public `/s/*` reads serve an anonymous visitor; the agent has the authenticated path. Duplicating is recreating from the same `sourceUrl` |
| **UI vocabulary, not domain** | `POST /trips/{id}/recompute`, `POST /stages/{id}/accommodations/manual`, `POST /trips/{tripId}/accommodations/scan`, `PATCH /users/me`, `/users/me/notification-preferences` | `TripBatchRecomputeRequest::$modifications` is the frontend's pending queue (`{stageId, type, label}`), not a concept a model can build; `analyze_trip` says what an agent means. Entering an accommodation by hand geocodes free text. Account preferences and language belong to the human |
| **Infrastructure** | `GET /trips/{id}/mercure-token`, `/api/health*`, the three `.well-known` | A Mercure token opens a long-lived connection per agent, excluded by ADR-064. The rest is operations |

### 3. A contract guard expressed as a transport artefact does not survive the transport

`If-Match` and `Idempotency-Key` become domain arguments: `version` and `idempotencyKey`. This
is ADR-063 generalised from authorization to concurrency.

They are not symmetric, and the apparent symmetry does not survive examination.

- **`version` is required, and never derived.** Deriving it would be `If-Match: *`, which is
  cancelling optimistic concurrency for this client. The agent reads before it writes.
- **`idempotencyKey` is optional and derived server-side**, over a five-minute window, from the
  user, the tool and the canonical arguments. Asking a model to mint a nonce fails in both
  directions: one literal reused refuses every creation after the first, a fresh one per attempt
  protects nothing. A version is an assertion only the caller can make; a key is bookkeeping only
  the server can be trusted with.

Both digests are taken over **canonicalised** arguments. Nothing obliges an agent to re-emit its
JSON keys in the order it used first, and without a recursive sort a legitimate retry hashes
differently — a phantom 409, and a valid confirmation token refused.

### 4. MCP has its own write chain

`api-platform/mcp` builds a `WriteProcessor` of its own rather than reusing
`api_platform.state_processor.write`. A guard declared with
`#[AsDecorator('api_platform.state_processor.write')]` therefore guards HTTP alone, and the
failure is silent: `unshare_trip` revoked a live share link on the call that was only supposed
to describe what revoking would do, because the confirmation decorator was not in that chain at
all. The lock and the precondition were equally absent.

Every write guard is declared against **both** chains, and a test asserts it.

### 5. A tool authorizes by URI variable, never by `object`

The `object` form resolves only once the provider has run, and a provider reports a missing
record by throwing — so an unknown id answers "not found" while someone else's answers "denied".
On HTTP, ADR-038's listener masks the second as the first. On this transport the MCP SDK catches
the exception itself and `kernel.exception` never runs, so the UUID oracle reopens. Naming the
URI variable evaluates the expression at `pre_read`, before anything can report absence.

The remedy is a static rule under CI, not a listener that uniformises errors: such a listener
would mask legitimate errors too and leave the `object` form in place.

### 6. `writable: false` guards nothing on an input DTO

This corrects the unit's own plan, which had it the other way round.

`AbstractItemNormalizer::getAllowedAttributes()` delegates to Symfony's own filter as soon as the
class is not an `#[ApiResource]`, and Symfony's filter sorts by serialization group — none is
declared on `TripRequest`, so nothing is filtered. `isWritable()` is never consulted. The
attribute describes the **published schema**; it has never guarded a row, on any transport.

What guards the row is the repository's explicit list of modifiable fields. `storeRequest()`
always went through it; `initializeTrip()` did not, and persisted the deserialized object
outright on a new trip — so `POST /trips` accepted `status`, `version`, `createdAt`, `outOfZone`,
`sourceType` and `computationStatus` from the body. That is fixed in the same unit, in the
repository, for both transports at once.

On MCP a second guard sits in front of it, and it is more precise than the attribute would have
been: **the tool's input schema is the contract, and an argument it does not publish is refused
by name**. Never dropped in silence — a dropped argument leaves the model no reason to stop
sending it.

### 7. Where the merge happens decides whether three guards work

The MCP handler defaults `deserialize` to false, so arguments must be turned into a record
somewhere. That somewhere is one decorator of the provider chain, at the slot
`DeserializeProvider` occupies on HTTP: **below validation, above `ReadProvider`.**

- Below validation, because constraints must judge the merged record. Checked against the stored
  one they all pass while the new values go in unexamined — and the CI guard demanding
  `validate: true` stays green while meaning nothing.
- Above `ReadProvider`, because it publishes `previous_data` as a clone of what it just read.
  Merge any deeper and the record "before" the edit is a copy of the record after it. Two guards
  read it: the lock would judge a trip by the values the caller just sent — so a future
  `startDate` unlocks the very trip being edited — and the update processor would compare a
  record with itself and recompute nothing, on every edit.

Measured, not deduced: moving the decorator one slot deeper breaks all three at once, and the
tests say which.

### 8. `validate: false` is the transport's default

Every write tool contradicts it explicitly, with the HTTP twin's `validationContext`, under a CI
guard. Without it a creation leaves for the workers with a `sourceUrl` nothing looked at, and
fails three messages later where nobody is listening.

### 9. Confirmation by token is not a human's agreement

A tool carrying the flag, called without a `confirmationToken`, writes nothing: it returns an
impact summary and a token, and carries the action out when called again with it.

It guarantees exactly two things: that an impact summary was produced and put in a transcript a
person can read back, and that **the arguments did not move between the two calls**. The same
agent makes both calls. It defends against a mis-parameterised mutation, not against a hostile
agent, and calling it "confirmation" without that sentence would be theatre.

The token is **random**, where ADR-079's consent handle is derived. That handle is not a secret:
it is re-read under the same user's Bearer, which is the real factor. Here the token *is* the
only factor, and a derived one would be computable from the arguments — the agent would forge it
without ever making the first call. **The 3A pattern does not transpose.**

Scope: what cannot be redone. `delete_trip`, `unshare_trip`, `update_trip_settings`. Not
`edit_stages`: the flag is declared per operation, so putting it there would impose it on all
five branches and double the edit loop — the likeliest real load on this system. Removing one day
can be redone; `destructiveHint` and a required `version` are the proportionate guard.

### 10. The short circuit is the innermost decorator, not the outermost

`DecoratorServicePass` hands the alias to the one it processes **last**, so the lowest priority
ends up outermost and runs first. The lock is at `-10`, the precondition at `0`, the confirmation
at `10`. Reversed, an agent would be sent to confirm a call that a started trip or a stale version
was going to refuse anyway, and would come back holding a token only to be told no.

### 11. A version that only travels in a header does not exist for this client

The read publishes it, and every write answers with the new one. Without the first, the nine
writing tools are unreachable — `TripRequest::$version` is `readable: false` and on HTTP the
version travels only as an `ETag`. Without the second, restructuring five days costs ten calls
instead of five. Corollary of ADR-078, which had one transport in view.

`add_waypoint` answers with no version and says so in words: it does not move the structural
version, so the one the agent holds is still good.

### 12. The agent's loop is the progress bar

No synchronous wait, anywhere. `ComputationTracker` is a Redis store with no pub/sub and no
blocking primitive, so waiting means polling inside the request: a FrankenPHP worker pinned for
up to 45 s with the browser clients queueing behind it, on a call most MCP clients cut at 30 s.
`create_trip` and `analyze_trip` return immediately with what to call next. This is ADR-057
transposed from the browser to the agent, which is better at it: it can say what it is waiting
for and do something else meanwhile.

Corollary: while the stages are not computed, `get_trip` says `partial: true`. `stages: []`
without it reads as "this trip has no days", which is false.

### 13. Read at two levels rather than truncate

`get_trip` answers a digest — settings, then one line per day — and `get_stage` drills in. A
trip digest that overruns the budget is a fault in the digest, not a case for truncation:
cutting a trip from the end removes the last days, whose alerts are no less important than the
first's. The budget is an assertion checked in a test, not a runtime valve.

### 14. No false hint, ever

An annotation is the one thing a client acts on without checking. `update_trip_settings` carries
`destructiveHint` although it is a PATCH, because changing the pacing recuts every day and
discards the manual split and every chosen accommodation — the annotation describes the effect,
not the verb. `analyze_trip` carries none, because `idempotentHint` would be false: it answers
409 when an analysis is already running.

Two tools lost the `idempotentHint` this unit's plan had pencilled in. `add_waypoint` dispatches
a routing request on every call, so "retry freely" is wrong even where the result is the same;
`choose_accommodation` is refused on a repeat, the version having moved. The rule wins over the
table.

`ToolAnnotations::fromArray()` silently ignores keys it does not know, so `readonlyHint` for
`readOnlyHint` ships as no hint at all. A CI guard pins the known keys.

## Known limits, assumed

- **No `oneOf` on `edit_stages`.** `SchemaFactory` produces one flat object from a class, so the
  five branches publish eleven optional fields. Two levers stand in: `action` documents what each
  branch needs, and a missing field is named in the refusal. A real `oneOf` would cost five tools.
- **`search_places` hits Nominatim inside an agent loop.** `limiter.geocode` already exceeds
  Nominatim's usage policy, and agent searches are diverse by nature — exactly the profile the
  24 h cache does not amortise. The risk is the project's IP being blocked, and it is the only
  tool that engages a third party the project depends on the goodwill of.
- **Prompt-injection posture is dated to unit 3C.** Third-party text reaching a model through
  `get_trip` and `get_stage` is marked as data at the single mapping point; systematic
  delimiting, an audit of error messages and an injection test are 3C. No third-party content is
  ever interpolated into a tool description or an error message: those are instructions, not
  data.
- **FrankenPHP worker mode is still not exercised under load.**
- **A `tools/call` cannot ask the human anything.** The SDK implements elicitation in full
  (`ResultType::InputRequired`), and `api-platform/mcp` exposes none of it — reachable only
  through `$context['mcp_session']`, outside API Platform's vocabulary. Hence the token, and
  hence §9.

## Consequences

The agent surface is thirteen tools with no second implementation behind them: every tool wraps
the processor its HTTP twin uses, and the wrappers rebuild the answer, never the rule. Three CI
guards watch the distance between what is declared and what runs — scope, `validate`, and
schema-versus-record — because that distance is where a name can diverge with nothing to say so.

The cost is that every tool now carries prose a model reads, and prose is not covered by a type
system. `#[ApiProperty(description:)]` is mandatory on every published argument, and a
description that drifts from behaviour is a defect no test can see.
