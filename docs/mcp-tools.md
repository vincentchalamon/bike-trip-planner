# MCP server reference

Bike Trip Planner exposes a [Model Context Protocol](https://modelcontextprotocol.io) server so
that an AI agent can read and plan trips on behalf of a user. This page lists what the server
publishes: the endpoint, how it is authorized, the 13 tools, their limits and their errors.

To connect a client step by step, see [Connect an AI agent](connect-an-ai-agent.md).

Design records: [ADR-063](adr/adr-063-transport-agnostic-authorization.md) (authorization
belongs to the domain, not the transport), [ADR-064](adr/adr-064-mcp-server-as-third-api-client.md)
(the MCP server is a third API client), [ADR-079](adr/adr-079-authorizing-an-agent-without-giving-it-a-session.md)
(OAuth 2.1 authorization server), [ADR-080](adr/adr-080-thirteen-tools-and-everything-that-is-not-one.md)
(the 13 tools and what is not one), [ADR-081](adr/adr-081-what-an-agent-can-make-the-server-believe-say-and-do.md)
(scope enforcement, untrusted text, rate limits), [ADR-082](adr/adr-082-the-table-carries-the-dates-the-tokens-carry-the-truth.md)
(authorized applications and revocation).

## Endpoint

| Property | Value |
|---|---|
| URL | `<DEFAULT_URI>/mcp`, for example `https://www.example.org/mcp` in production and `https://localhost/mcp` in development |
| Transport | Streamable HTTP only (no stdio) |
| Server name / version | `bike-trip-planner` / `1.0.0` |
| Accepted `Host` | The host of `DEFAULT_URI` only (DNS-rebinding protection); any other `Host` header is refused |
| Credential | `Authorization: Bearer <access token>` issued by this server's OAuth authorization server |

`DEFAULT_URI` derives from `DOMAIN` (see [Deployment](deployment.md)). The same value is the
OAuth issuer and the base of the canonical resource URI, so every URL below starts with it.

A session token of the web or mobile app is refused on `/mcp` (it is signed with a different
key), and an agent access token is refused on the REST API.

## Authorization

The server is an OAuth 2.1 authorization server and protected resource in the same deployment.

### Discovery

| Document | URL |
|---|---|
| Protected resource metadata (RFC 9728) | `/.well-known/oauth-protected-resource/mcp` and `/.well-known/oauth-protected-resource` (same content) |
| Authorization server metadata (RFC 8414) | `/.well-known/oauth-authorization-server` |

Both are public, served with `Access-Control-Allow-Origin: *` and `Cache-Control: public, max-age=3600`.

Protected resource metadata:

| Field | Value |
|---|---|
| `resource` | `<DEFAULT_URI>/mcp` |
| `authorization_servers` | `[<DEFAULT_URI>]` |
| `scopes_supported` | `trips:read`, `trips:write` |
| `bearer_methods_supported` | `header` |

Authorization server metadata:

| Field | Value |
|---|---|
| `issuer` | `<DEFAULT_URI>` |
| `authorization_endpoint` | `<DEFAULT_URI>/oauth/authorize` |
| `token_endpoint` | `<DEFAULT_URI>/oauth/token` |
| `response_types_supported` | `code` |
| `grant_types_supported` | `authorization_code`, `refresh_token` |
| `code_challenge_methods_supported` | `S256` |
| `token_endpoint_auth_methods_supported` | `none` (public clients only) |
| `client_id_metadata_document_supported` | `true` |
| `authorization_response_iss_parameter_supported` | `true` |

An unauthenticated or rejected call to `/mcp` answers:

```http
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer resource_metadata="<DEFAULT_URI>/.well-known/oauth-protected-resource/mcp", scope="trips:read trips:write"
Content-Type: application/json

{"error":"invalid_token","error_description":"A valid access token for this resource is required."}
```

### Clients

- **Client ID Metadata Documents (CIMD).** A client names itself by the HTTPS URL of its
  metadata document, used as `client_id`. The document is fetched on the first authorization
  and cached (between 5 minutes and 24 hours, following its `Cache-Control: max-age`). The
  rules it must meet are listed below.
- **Dynamic client registration (RFC 7591) is not implemented.**
- A client whose `client_id` is not an `https://` URL must already exist in the database
  (created by an operator, see [Connect an AI agent](connect-an-ai-agent.md#local-development)).
- A CIMD client is stored as a public client: no secret, PKCE `S256` required (plain PKCE
  refused), grants `authorization_code` and `refresh_token` only. Client credentials, password, implicit and
  device code grants are disabled.

A CIMD client is accepted only if:

| Check | Rule |
|---|---|
| `client_id` URL | `https`, with a path (not a bare origin), no fragment, no credentials, not on this server's own host |
| Fetch | No redirect followed, no connection to a private network address, response `200`, at most 64 KiB |
| Document | JSON object whose `client_id` equals the URL byte for byte |
| `client_name` | Non-empty string, at most 128 characters |
| `redirect_uris` | 1 to 10 entries, each `https://...` or `http://127.0.0.1...` / `http://[::1]...` (`http://localhost` is refused) |

### Authorization flow

1. The client sends the browser to `/oauth/authorize` with PKCE, the scopes it wants and,
   optionally, `resource=<DEFAULT_URI>/mcp`.
2. The browser must be signed in to the web app (its refresh cookie is the only credential
   accepted there). A signed-out browser is redirected to `/login`; the authorization request
   is not preserved and must be started again from the client.
3. The server parks the request for 10 minutes and redirects to the consent page
   `/oauth/consent/<handle>` of the web app. The page shows the client name, the scopes, the
   host of the redirect URI and, when every redirect URI is a loopback address, a warning.
4. The user chooses **Authorize** or **Refuse**. The browser then returns to
   `/oauth/authorize`, which redirects to the client with `code` (or `error`) and `iss`.
5. The client exchanges the code at `/oauth/token` (with `resource` if it sends one).

A request that names no scope is refused with `invalid_scope`. A `resource` other than
`<DEFAULT_URI>/mcp` is refused with `invalid_target` on both endpoints.

### Tokens

| Token | Lifetime |
|---|---|
| Authorization code | 2 minutes, single use |
| Access token | 15 minutes; `aud` contains `<DEFAULT_URI>/mcp` and is checked on every call |
| Refresh token | 1 month; rotated on each use (the old one is revoked) |

Access is revoked when the user revokes the application, changes their email address, or
deletes their account. Signing out of the web or mobile app does not revoke it.

### Scopes

| Scope | Grants | Tools |
|---|---|---|
| `trips:read` | Read the user's trips, their days, and search places | `list_trips`, `get_trip`, `get_stage`, `search_places` |
| `trips:write` | Create, change, share and delete the user's trips | `create_trip`, `update_trip_settings`, `edit_stages`, `add_waypoint`, `choose_accommodation`, `analyze_trip`, `share_trip`, `unshare_trip`, `delete_trip` |

- The two scopes are independent: `trips:write` does not include `trips:read`. An agent that
  edits trips needs both, since every edit needs the `version` returned by `get_trip`.
- Scopes are account-wide: `trips:read` reaches every trip of the user. There is no per-trip
  consent.
- A scope never grants access to another user's trip. Ownership is checked on every call, and
  another user's trip answers exactly like a trip that does not exist.
- `tools/list` only lists the tools the token's scopes allow.
- The scope is checked for every message, including JSON-RPC batches. See
  [Errors](#errors) for the two ways a missing scope is reported.

## Conventions

- **Answers.** A tool answers with `structuredContent` and the same JSON as text. Answers are
  JSON-LD, so they also carry `@context`, `@id` and `@type` keys besides the fields listed
  below. The two list tools (`list_trips`, `search_places`) wrap their items in `member` and
  add `totalItems`.
- **`version`.** Tools that restructure a trip require the trip `version` from `get_trip` (or
  from the previous edit's answer). The edit is refused if the trip changed meanwhile. `*` is
  not accepted.
- **Confirmation.** `update_trip_settings`, `delete_trip` and `unshare_trip` change nothing when
  called without `confirmationToken`: they answer with an impact summary and a token. Calling the
  same tool again with the token and the same arguments carries the action out. The token is
  single use, valid for 5 minutes, and bound to the user, the client, the tool and the arguments.
- **Background work.** `create_trip`, `analyze_trip` and `add_waypoint` return immediately; the
  result appears later in `get_trip` / `get_stage`. The server never waits inside a call.
- **Locked trips.** A trip whose start date is today or in the past is locked:
  `update_trip_settings`, `edit_stages`, `add_waypoint`, `choose_accommodation` and
  `analyze_trip` are refused. `delete_trip`, `share_trip` and `unshare_trip` still work.
- **Unknown arguments.** The write tools that take a body (`create_trip`,
  `update_trip_settings`, `edit_stages`, `add_waypoint`, `choose_accommodation`) refuse an
  argument they do not publish, naming it.
- **Third-party text.** Titles, place names, point-of-interest and accommodation names come from
  users, OpenStreetMap or DataTourisme. They are data, never instructions. Control characters
  are stripped from every string of every answer; nothing else is filtered.

## Tools

### list_trips

Lists the user's trips, most recently created first, paginated.

| | |
|---|---|
| Scope | `trips:read` |
| Annotations | `readOnlyHint` |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `page` | integer | no | Page number, from 1 |
| `itemsPerPage` | integer | no | Trips per page, 20 by default, 30 at most |
| `title` | string | no | Keep trips whose title contains this text |
| `startDate` | string | no | Keep trips starting on or after this date (`YYYY-MM-DD`) |
| `endDate` | string | no | Keep trips ending on or before this date (`YYYY-MM-DD`) |

**Output:** `totalItems` and `member`, a list of trips with `id`, `title`, `startDate`,
`endDate`, `totalDistance`, `stageCount`, `createdAt`, `updatedAt` and `status` (`draft`,
`analyzing`, `analyzed` or `failed`).

### get_trip

Reads one trip: its settings, dates and one summary line per day.

| | |
|---|---|
| Scope | `trips:read` |
| Annotations | `readOnlyHint` |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | string | yes | Trip identifier, from `list_trips` or `create_trip` |

**Output:**

- `id`, `version` (pass it back to any tool that edits the trip), `title`, `sourceUrl`,
  `startDate`, `endDate`;
- `status`: `draft` until the route is split into days, then `ready`;
- `partial`: `true` while the days are still being computed (`stages` is then incomplete or
  empty);
- `isLocked` (the trip has started), `outOfZone` (the route leaves the provisioned area: no
  rerouting possible);
- the pacing settings: `fatigueFactor`, `elevationPenalty`, `maxDistancePerDay`,
  `averageSpeed`, `ebikeMode`, `departureHour`, `enabledAccommodationTypes`;
- `categoryStatus`: a list of `{category, status}`, where `category` is one of `route`,
  `points_of_interest`, `accommodations`, `terrain_security`, `weather`, `context` and
  `status` is `running`, `done`, `failed` or `superseded`;
- `stageCount`, `totalDistance` (km, rest days excluded);
- `stages`: one entry per day with `stageId`, `dayNumber`, `distance` (km), `elevation` (m),
  `startLabel`, `endLabel`, `isRestDay`, `accommodation` (chosen name or `null`), `alertCount`
  and `criticalAlerts` (rendered messages of the critical alerts).

### get_stage

Reads one day of a trip in full. The route geometry is not included.

| | |
|---|---|
| Scope | `trips:read` |
| Annotations | `readOnlyHint` |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `tripId` | string | yes | Trip identifier |
| `stageId` | string | yes | Day identifier, as listed in `stages` by `get_trip` |

**Output:** `id`, `dayNumber`, `distance` (km), `elevation` and `elevationLoss` (m),
`startPoint` and `endPoint` (`{lat, lon, ele}`), `label`, `isRestDay`, `weather`, `alerts`
(list of objects as their producers publish them), `resupply`, `accommodations`,
`selectedAccommodation` and `events`.

### search_places

Finds a place by name and returns its coordinates. Queries the public Nominatim
(OpenStreetMap) service.

| | |
|---|---|
| Scope | `trips:read` |
| Annotations | `readOnlyHint`, `openWorldHint` |
| Extra limit | `geocode`: 30 calls per minute per user, shared with the web and mobile apps |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `q` | string | yes | What to look for: a town, a pass, a landmark |
| `limit` | integer | no | Number of places, clamped to 1-10, default 5 |

**Output:** `totalItems` and `member`, a list of places with `name`, `lat`, `lon`,
`displayName` (full address) and `type` (OpenStreetMap class such as `city`, `village`, `peak`).

### create_trip

Creates a trip from a public route URL. The route is fetched and cut into days in the
background.

| | |
|---|---|
| Scope | `trips:write` |
| Annotations | none |
| Extra limit | `trip_create`: 10 creations per minute per user, shared with the web and mobile apps |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `sourceUrl` | string | yes | Komoot tour or collection, Strava route, or RideWithGPS route, `https` only (see [Supported route sources](route-sources.md)) |
| `title` | string | no | Trip name; defaults to the source title |
| `startDate` | string | no | Departure date, RFC 3339 (for example `2026-07-01T00:00:00+00:00`); needed for weather, opening days and events |
| `endDate` | string | no | Last day, RFC 3339; without it the number of days follows from the distance |
| `fatigueFactor` | number | no | How much shorter each day is than the previous one, 0.5 to 1.0 |
| `elevationPenalty` | number | no | Metres of ascent that remove one kilometre from a day, default 50 |
| `ebikeMode` | boolean | no | E-bike pacing, and charging points are looked for |
| `departureHour` | integer | no | Usual departure hour, 0 to 23, default 8 |
| `maxDistancePerDay` | number | no | Cap on a day in km, 30 to 300, default 80 |
| `averageSpeed` | number | no | Average speed in km/h, 5 to 50, default 15 |
| `enabledAccommodationTypes` | array of strings | no | Any of `camp_site`, `hostel`, `alpine_hut`, `chalet`, `guest_house`, `hotel`, `wilderness_hut`; at least one; default all |
| `idempotencyKey` | string | no | Leave out in general. Only to make two identical creations distinct; 16 to 255 characters from `[A-Za-z0-9_-]` |

**Output:** `id`, `result` and `nextAction` (call `get_trip` with the id; days usually appear
within 10 to 40 seconds, until then `partial` is `true`). No `version` is returned.

**Side effects:** creates a trip and starts the computation. Without `idempotencyKey`, an
identical call (same user, same arguments) in the same or previous 5-minute window returns the
same trip instead of creating a second one. A trip cannot be created from a file through MCP.

### update_trip_settings

Changes how a trip is planned: distance per day, fatigue, dates, accommodation types. Only the
arguments sent are changed.

| | |
|---|---|
| Scope | `trips:write` |
| Annotations | `destructiveHint` |
| Confirmation | Yes |
| Requires | `version`; trip not locked |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | string | yes | Trip identifier |
| `version` | integer | yes | Trip version from `get_trip` or the previous edit |
| `confirmationToken` | string | no | Leave out on the first call; send the token from the first answer to apply |
| `title` | string | no | New name |
| `startDate` | string | no | New departure date, RFC 3339 |
| `endDate` | string | no | New last day, RFC 3339 |
| `fatigueFactor` | number | no | 0.5 to 1.0 |
| `elevationPenalty` | number | no | Metres of ascent per kilometre removed |
| `ebikeMode` | boolean | no | E-bike pacing |
| `departureHour` | integer | no | 0 to 23 |
| `maxDistancePerDay` | number | no | 30 to 300 km |
| `averageSpeed` | number | no | 5 to 50 km/h |
| `enabledAccommodationTypes` | array of strings | no | Same values as `create_trip`; at least one |

`sourceUrl` cannot be changed; create a new trip instead.

**Output:** first call: `confirmationRequired: true`, `confirmationToken`, `action` (what will
happen) and `impact` (`tripId`, `title`, `stageCount`, `startDate`, `endDate`,
`hasActiveShareLink`). Confirmed call: `result`, `version`, `nextAction`.

**Side effects:** usually recuts the whole trip, which discards any manual day split and every
chosen accommodation.

### edit_stages

Restructures the days of a trip. `action` selects the operation.

| | |
|---|---|
| Scope | `trips:write` |
| Annotations | `destructiveHint` |
| Confirmation | No |
| Requires | `version`; trip not locked |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `tripId` | string | yes | Trip identifier |
| `action` | string | yes | `add`, `update`, `move`, `delete` or `rest_day` (see below) |
| `version` | integer | yes | Trip version from `get_trip` or the previous edit |
| `stageId` | string | depends | Day to act on; required by every action except `add` |
| `position` | integer | no | `add` only: where to insert, 0-based, default at the end |
| `toIndex` | integer | depends | `move` only: target position, 0-based |
| `startPoint` | object `{lat, lon, ele}` | depends | Where the day starts; required by `add`, optional for `update` |
| `endPoint` | object `{lat, lon, ele}` | depends | Where the day ends; required by `add`, optional for `update`; also moves the start of the next day |
| `label` | string | no | Free-text name for the day (`add`, `update`) |
| `distance` | number | no | `update` only: target distance in km, splitting the day at that point |

| `action` | Needs | Effect |
|---|---|---|
| `add` | `startPoint`, `endPoint`, optional `position` | Inserts a day and renumbers the following ones |
| `update` | `stageId` plus any of `startPoint`, `endPoint`, `label`, `distance` | Changes a day and recalculates its route |
| `move` | `stageId`, `toIndex` | Reorders a day |
| `delete` | `stageId` | Removes a day and merges it with its neighbour |
| `rest_day` | `stageId` | Inserts a rest day after that day; every following date shifts by one |

The published schema marks only `tripId`, `action` and `version` as required; a branch called
without the field it needs is refused with a message naming it.

**Output:** `result`, `version` (use it for the next edit, no need to read the trip again),
`nextAction` when there is one.

### add_waypoint

Reroutes one day through a given point, for example a point of interest listed by `get_stage`.

| | |
|---|---|
| Scope | `trips:write` |
| Annotations | none |
| Confirmation | No |
| Requires | trip not locked (no `version`) |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `tripId` | string | yes | Trip identifier |
| `stageId` | string | yes | Day to reroute |
| `waypointLat` | number | yes | Latitude of the place to route through |
| `waypointLon` | number | yes | Longitude of the place to route through |

**Output:** `result`. No `version`: the trip version does not change, so a version already held
stays valid.

**Side effects:** dispatches a routing request on every call; the day's distance and climbing
change when it completes.

### choose_accommodation

Chooses where to sleep at the end of one day, or clears the choice. The day then ends at that
place and the next day starts from it.

| | |
|---|---|
| Scope | `trips:write` |
| Annotations | none |
| Confirmation | No |
| Requires | `version`; trip not locked |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `tripId` | string | yes | Trip identifier |
| `stageId` | string | yes | Day to sleep on |
| `version` | integer | yes | Trip version from `get_trip` or the previous edit |
| `selectedAccommodationLat` | number | no | Latitude, copied from the `accommodations` of `get_stage`; leave both coordinates out to clear the choice |
| `selectedAccommodationLon` | number | no | Longitude of the chosen place |

**Output:** `result`, `version`, `nextAction` when there is one.

### analyze_trip

Recomputes everything known about each day: points of interest, accommodation, weather,
terrain, resupply, events and alerts.

| | |
|---|---|
| Scope | `trips:write` |
| Annotations | none |
| Confirmation | No |
| Requires | trip not locked |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | string | yes | Trip identifier |

**Output:** `result` and `nextAction` (call `get_trip` again and read `categoryStatus`).

**Side effects:** starts the enrichment pipeline in the background. Refused while an analysis
is already running for the trip.

### share_trip

Publishes a read-only public link to a trip and returns its address.

| | |
|---|---|
| Scope | `trips:write` |
| Annotations | none |
| Confirmation | No |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `tripId` | string | yes | Trip identifier |

**Output:** `url` (public address; append `.gpx` or `.fit` to download the trip), `shortCode`,
`createdAt`.

**Side effects:** anyone with the link can view and download the trip without an account.
Calling it again returns the existing link.

### unshare_trip

Revokes a trip's public link.

| | |
|---|---|
| Scope | `trips:write` |
| Annotations | `destructiveHint` |
| Confirmation | Yes |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `tripId` | string | yes | Trip identifier |
| `confirmationToken` | string | no | Leave out on the first call; send the token from the first answer to apply |

**Output:** first call: `confirmationRequired`, `confirmationToken`, `action`, `impact`.
Confirmed call: `result`.

**Side effects:** the address stops working at once for everyone. The trip is untouched; a new
link gets a different address.

### delete_trip

Deletes a trip permanently, with its days and any public link.

| | |
|---|---|
| Scope | `trips:write` |
| Annotations | `destructiveHint` |
| Confirmation | Yes |

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | string | yes | Trip identifier |
| `confirmationToken` | string | no | Leave out on the first call; send the token from the first answer to apply |

**Output:** first call: `confirmationRequired`, `confirmationToken`, `action`, `impact`.
Confirmed call: `result`.

**Side effects:** irreversible. Works on a locked trip.

## Rate limits

All limits use a sliding window.

| Limiter | Limit | Key | Applies to |
|---|---|---|---|
| `mcp_envelope` | 300 requests / minute | Client IP | Every HTTP request to `/mcp`, before authentication |
| `mcp_tool_call` | 60 calls / minute | User + OAuth client | Every tool call (each message of a batch counts) |
| `mcp_mutation` | 20 calls / minute | User + OAuth client | Calls to `trips:write` tools, on top of `mcp_tool_call` |
| `trip_create` | 10 / minute | User | `create_trip` (shared with the apps) |
| `geocode` | 30 / minute | User | `search_places` (shared with the apps) |
| `oauth_authorize` | 30 / 5 minutes | User | `/oauth/authorize` |
| `oauth_token` | 60 / minute | Client IP | `/oauth/token` |
| `oauth_client_metadata_user` | 20 / hour | User | Resolving a new CIMD client |
| `oauth_client_metadata_host` | 60 / hour | Host of the `client_id` | Resolving a new CIMD client |

Two agents of the same user have separate `mcp_tool_call` and `mcp_mutation` budgets.

## Errors

### HTTP errors

| Status | Body `error` | When |
|---|---|---|
| 401 | `invalid_token` | No token, or a token that is expired, revoked, signed by another issuer (such as an app session token), or not issued for `<DEFAULT_URI>/mcp`. Always with the `WWW-Authenticate` header shown above |
| 403 | `insufficient_scope` | The request's `Mcp-Name` header names a tool the token's scopes do not allow. `WWW-Authenticate: Bearer error="insufficient_scope", scope="<scope>", resource_metadata="..."` |
| 429 | `rate_limited` | `mcp_envelope` exceeded; `Retry-After` gives the delay in seconds |

On the OAuth endpoints:

| Status | Body `error` | When |
|---|---|---|
| 400 | `invalid_client` | A CIMD `client_id` could not be resolved or was rejected (the reason is logged, not returned) |
| 400 | `invalid_scope` | The authorization request names no scope |
| 400 | `invalid_target` | `resource` is not `<DEFAULT_URI>/mcp` |
| 400 | `server_error` | The account's email is longer than 128 characters and cannot be granted to an application |
| 429 | | `oauth_authorize`, `oauth_token` or CIMD resolution limit exceeded |

### JSON-RPC errors

Once a request is authenticated, refusals come back as JSON-RPC errors in an HTTP response
the MCP SDK writes.

| Code | Message / data | When |
|---|---|---|
| -32000 | `insufficient_scope: ...`, `data: {"error": "insufficient_scope", "scope": "<scope>"}` | The token lacks the tool's scope and the call was not caught by the HTTP 403 check (no or encoded `Mcp-Name` header, batch). Also returned, with `scope: null`, for a tool name the server does not know |
| -32000 | `rate_limited: too many calls from this agent. Retry in N seconds.`, `data: {"error": "rate_limited", "retryAfter": N}` | `mcp_tool_call` or `mcp_mutation` exceeded |
| -32602 | `Invalid parameters for tool '<name>': ...` | Arguments do not match the tool's input schema |
| -32603 | Message of the refusal | Any refusal raised while running the tool (see below) |

Refusals raised by a tool carry a message meant to be acted on. Examples from the code:

| Situation | Message |
|---|---|
| Missing `version` | `This tool requires a "version" argument: the trip version you are editing, as returned by "get_trip". ...` |
| Stale `version` | `Trip <id> has moved on: you edited version N, the current one is M. Reload it and reapply your change.` |
| Locked trip | `This trip is locked: its start date is today or in the past.` |
| Analysis running | `An analysis is already in progress for this trip.` |
| Bad confirmation token | `This "confirmationToken" is not valid for this call: it was minted for different arguments, has already been used, or has expired. ...` |
| Unpublished argument | `Unknown argument(s): "...". This tool accepts: ...` |
| `edit_stages` without a valid `action`, or without `stageId` | `Unknown "action": ...` / `The "<action>" action needs a "stageId": ...` |
| `idempotencyKey` reused with other arguments | `This "idempotencyKey" was already used for a different request body.` |

A trip or day that does not exist and one that belongs to another user give the same answer.
Values sent by the caller are quoted in messages cut to 40 characters.
