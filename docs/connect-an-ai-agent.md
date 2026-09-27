# Connect an AI agent

This guide connects an MCP client (an AI assistant, an agent framework, the MCP Inspector, or
any client that supports remote MCP servers with OAuth) to Bike Trip Planner, so that it can
read and plan your trips. The tools, parameters and error codes are listed in the
[MCP server reference](mcp-tools.md).

## Before you start

You need a Bike Trip Planner account, a browser signed in to the web app, and an MCP client
that supports:

- the **Streamable HTTP** transport (stdio is not offered);
- the **OAuth 2.1** authorization code flow with PKCE `S256`, as a public client (no secret);
- **Client ID Metadata Documents**: the client identifies itself with the HTTPS URL of its
  metadata document. Dynamic client registration is not supported. A client that cannot do
  this needs a client registered by an operator (see [Local development](#local-development)
  for the command);
- redirect URIs that are `https://...` or a literal loopback address
  (`http://127.0.0.1:<port>/...` or `http://[::1]:<port>/...`). `http://localhost` is refused
  in a metadata document.

## 1. Add the server to your client

In your client, add a remote MCP server with this URL:

```text
https://<host>/mcp
```

`<host>` is the host the deployment is served from, the host of `DEFAULT_URI` (in production
`www.<DOMAIN>`, see [Deployment](deployment.md)). Use exactly that host: requests with another
`Host` header are refused. In local development the URL is `https://localhost/mcp`.

No token has to be pasted. On the first request the server answers `401` with a
`WWW-Authenticate` header pointing at
`https://<host>/.well-known/oauth-protected-resource/mcp`, from which a compliant client finds
the authorization server and starts the flow below by itself.

## 2. Authorize the client

1. The client opens your browser on `https://<host>/oauth/authorize`.
2. If the browser is not signed in to Bike Trip Planner, you are sent to the login page. The
   authorization request is **not** kept: sign in (magic link), then start the connection again
   from the client.
3. The consent page **Authorize this application** shows:
    - the name the application gives itself (text it chose, so check the next line too);
    - what it asks to do, one line per scope;
    - the host it will be sent back to;
    - a warning when the application only returns to your own machine (loopback address):
      authorize it only if you just started it yourself.
4. Click **Authorize** or **Refuse**. The browser returns to the client, which finishes the
   exchange. The consent page is valid for 10 minutes and can be answered once.

The permissions an application can ask for:

| Scope | Shown as | Allows |
|---|---|---|
| `trips:read` | read your trips and their stages | `list_trips`, `get_trip`, `get_stage`, `search_places` |
| `trips:write` | create and edit your trips | create, change, analyze, share, unshare and delete trips |

The consent is all or nothing for what the client asks. Both scopes cover **every** trip of
your account; there is no per-trip consent. An agent that edits trips needs both scopes,
because edits require the trip `version` returned by `get_trip`. A client given only
`trips:read` does not even see the write tools.

After consent, the client holds an access token valid 15 minutes and a refresh token valid one
month, renewed as it is used. You are not asked again while the refresh token lives.

## 3. Confirm destructive actions

Three tools never act on the first call:

| Tool | What is at stake |
|---|---|
| `delete_trip` | The trip, its days and its public link, permanently |
| `unshare_trip` | The public link; anyone holding it loses access |
| `update_trip_settings` | Usually recuts every day, discarding manual day changes and chosen accommodations |

Called without `confirmationToken`, such a tool changes nothing and answers with what would be
affected (trip title, number of days, dates, whether a public link is active) and a token. A
well-behaved agent reports that to you and waits. If you agree, it calls the same tool again
with the token and the same arguments. The token works once, for 5 minutes, for those exact
arguments only.

This is not a lock against the agent: the same agent receives and spends the token. It makes
the impact visible in the conversation before anything happens. Other writes are not
confirmed; in particular `share_trip` publishes a link immediately, and `edit_stages` changes
days directly.

## 4. Review or revoke access

Every application you authorized is listed under **Authorized applications**:

- **Web:** click your profile circle in the top bar to open the account settings
  (`/account/settings`), then the **Authorized applications** section.
- **Mobile:** open the **Account** tab, then **Authorized applications**.

Each entry shows the application name, its host (the part of its identity it cannot choose:
trust this line more than the name), when it was authorized and when it last called a tool
(or "never used"). An entry disappears by itself once the application no longer holds a valid
token.

To cut an application off, click **Revoke** and confirm. Its tokens stop working at once, for
your account only. To use it again, authorize it again from the application.

Access is also revoked when you change your email address or delete your account. Signing out
of the web or mobile app does **not** revoke it.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `401` with `"error": "invalid_token"` | No token, token expired or revoked, token not issued for `https://<host>/mcp`, or an app session token used instead of an OAuth token | Let the client re-authorize. Check the server URL uses the deployment host |
| `403` with `"error": "insufficient_scope"`, or a JSON-RPC error `insufficient_scope: ...` whose `data.scope` names a scope | The token lacks the scope the tool needs | Re-authorize, asking for both `trips:read` and `trips:write` |
| Write tools missing from the tool list | The token only carries `trips:read` | Same as above |
| `400` `invalid_client` on `/oauth/authorize` | The client's metadata document could not be fetched or was rejected (not HTTPS, redirect, private address, over 64 KiB, `client_id` not equal to its URL, missing `client_name`, bad `redirect_uris`) | Fix the client's metadata document; the exact reason is only in the server log |
| `400` `invalid_scope` | The authorization request named no scope | Configure the client to request `trips:read trips:write` |
| `400` `invalid_target` | The client sent a `resource` other than `https://<host>/mcp` | Use the exact server URL |
| `400` `server_error`, "This account cannot be granted to an application." | Your email address is longer than 128 characters | Use an account with a shorter address |
| After signing in you land on the home page, not the consent page | The authorization request was dropped at login | Start the connection again from the client while signed in |
| Consent page says "This request is no longer valid" | Expired (10 minutes), already answered, or started by another account | Start the connection again from the client |
| JSON-RPC error `rate_limited: ... Retry in N seconds.` | More than 60 tool calls, or 20 write calls, per minute for this user and this client | Wait `data.retryAfter` seconds |
| `429` with `Retry-After` | More than 300 requests per minute from one IP to `/mcp`, or too many OAuth requests | Wait and retry |
| `Trip <id> has moved on: ...` | The trip changed since the `version` the agent sent | Read it again with `get_trip` and reapply |
| `This trip is locked: ...` | The trip's start date is today or past; its days can no longer be edited | Nothing to fix; deleting and sharing still work |
| `This "confirmationToken" is not valid for this call: ...` | Token expired, already used, or arguments changed | Call the tool again without a token |

## Local development

The development stack serves the MCP server at `https://localhost/mcp` behind Caddy's locally
signed certificate, so the client must trust that certificate.

1. Start the stack with `make start-dev` (see [Getting started](getting-started.md)). On boot,
   the `php` container generates the authorization server's signing keys in
   `api/config/oauth/` if they are missing (unencrypted, git-ignored). They must differ from the
   session JWT keys in `api/config/jwt/`. For the test suite, `make keypairs-test` regenerates
   both pairs with `scripts/generate-keypairs.sh`; `make start` does the same for the iso-prod
   stack in `.docker/oauth-recette/`.
2. Sign in to `https://localhost` with a magic link read in Mailcatcher
   (`http://localhost:1080`).
3. A client whose metadata document is not publicly reachable cannot use CIMD here (the server
   refuses private network addresses and its own host). Register a public client instead, with
   the exact callback URL your client uses, from `make php-shell`:

    ```bash
    bin/console league:oauth2-server:create-client \
        --public \
        --redirect-uri=http://127.0.0.1:6274/oauth/callback \
        --grant-type=authorization_code --grant-type=refresh_token \
        --scope=trips:read --scope=trips:write \
        "MCP Inspector (dev)" mcp-inspector-dev
    ```

    Then configure the client with the client ID `mcp-inspector-dev`. The callback URL above is
    an example; register the one your client actually sends.

4. `bin/console debug:mcp` lists the configured server and its tools.
