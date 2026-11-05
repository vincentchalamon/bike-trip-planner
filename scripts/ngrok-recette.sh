#!/usr/bin/env sh
# Expose the RUNNING recette stack over an ngrok tunnel for mobile browser
# testing, without changing your ngrok command.
#
# Usage:
#   1. Boot recette once with the URL-agnostic PWA build (`make start-recette` also
#      writes the untracked .env.recette secrets file on first run):
#        make start-recette
#   2. Start your tunnel to the local Caddy (default HTTPS). The tunnel makes the
#      recette public: put an ngrok identity gate in front of it when you only
#      test from a browser:
#        ngrok http https://localhost --oauth=google --oauth-allow-email=<you>@<domain>
#      (ngrok's --basic-auth is not an option: it takes over the Authorization
#      header the API reads its Bearer token from.) The native mobile app cannot
#      pass an OAuth gate, so for on-device testing drop the flag, keep the tunnel
#      up only for the session and stop it afterwards.
#   3. Point the backend at the tunnel host (re-run whenever the ngrok URL
#      changes — ngrok-free rotates it on every restart):
#        scripts/ngrok-recette.sh <ngrok-host>
#      e.g. scripts/ngrok-recette.sh abcd-1234.ngrok-free.app
#
# Why: Caddy only serves the hosts in SERVER_NAME. ngrok connects to the local
# Caddy over TLS with SNI=localhost and forwards the original Host (the ngrok
# domain), so Caddy must serve BOTH: "localhost" (for the tunnel's TLS handshake)
# and the ngrok domain (to route + trust the request). local_certs keeps every
# cert self-signed (no failing Let's Encrypt attempt for the ngrok domain). The
# PWA bundle is already origin-relative, so no rebuild is needed on URL change.
set -eu

[ -f .env.recette ] || { echo "Missing .env.recette: run make start-recette first (from the repo root)." >&2; exit 1; }

HOST="${1:?Usage: $0 <ngrok-host> (e.g. abcd-1234.ngrok-free.app)}"
# Escape dots for the TRUSTED_HOSTS regex.
ESC=$(printf '%s' "$HOST" | sed 's/[.]/\\./g')

SERVER_NAME="localhost, $HOST" \
CADDY_GLOBAL_OPTIONS="local_certs" \
TRUSTED_HOSTS="^(localhost|$ESC|php)$" \
FRONTEND_URL="https://$HOST" \
  docker compose --env-file .env --env-file .env.recette -f compose.yaml -f compose.recette.yaml up -d --no-deps php

echo "Recette now served for https://$HOST (and https://localhost)."
echo "Open https://$HOST on your phone (accept the ngrok interstitial once)."
