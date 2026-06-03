#!/usr/bin/env bash
#
# Recette seed (Sprint 35.2 v2) — iso-prod-faithful, option 2.
#
# Boots an authenticated session on the running recette stack (see `make start-recette`)
# via the REAL passwordless flow (app:create-user -> Mailcatcher -> /auth/verify), then
# creates the trips from the user-provided Komoot links. Prints RECETTE_JWT and the
# trip ids for the audit steps to reuse.
#
# Run from the repo root, AFTER `make start-recette`. Requires python3 + curl.
set -euo pipefail

BASE_URL="${BASE_URL:-https://localhost}"
MAILCATCHER_URL="${MAILCATCHER_URL:-http://localhost:1080}"
RECETTE_EMAIL="${RECETTE_EMAIL:-recette@example.com}"
LOCALE="${RECETTE_LOCALE:-fr}"
COMPOSE="docker compose -f compose.yaml -f compose.recette.yaml"
CURL="curl -sk"

json() { python3 -c "import sys,json;$1"; }

echo "==> Purging Mailcatcher inbox"
$CURL -X DELETE "$MAILCATCHER_URL/messages" >/dev/null 2>&1 || true

echo "==> Ensuring user $RECETTE_EMAIL (app:create-user, idempotent)"
$COMPOSE exec -T php bin/console app:create-user "$RECETTE_EMAIL" --locale="$LOCALE" || true

echo "==> Requesting a fresh magic link"
$CURL -X POST "$BASE_URL/auth/request-link" -H 'Content-Type: application/ld+json' \
  -d "{\"email\":\"$RECETTE_EMAIL\"}" >/dev/null

echo "==> Reading magic link from Mailcatcher"
sleep 2
MSG_ID=$($CURL "$MAILCATCHER_URL/messages" | json 'm=json.load(sys.stdin);print(m[-1]["id"] if m else "")')
[ -n "$MSG_ID" ] || { echo "ERROR: no mail captured in Mailcatcher" >&2; exit 1; }
TOKEN=$($CURL "$MAILCATCHER_URL/messages/$MSG_ID.html" | grep -oE 'auth/verify/[A-Za-z0-9_-]+' | head -1 | sed 's#auth/verify/##')
[ -n "$TOKEN" ] || { echo "ERROR: no /auth/verify token in mail $MSG_ID" >&2; exit 1; }

echo "==> Verifying token -> JWT"
RECETTE_JWT=$($CURL -X POST "$BASE_URL/auth/verify" -H 'Content-Type: application/ld+json' \
  -d "{\"token\":\"$TOKEN\"}" | json 'print(json.load(sys.stdin)["token"])')
[ -n "$RECETTE_JWT" ] || { echo "ERROR: /auth/verify did not return a JWT" >&2; exit 1; }

create_trip() { # url start end maxDistancePerDay [ebike]
  $CURL -X POST "$BASE_URL/trips" \
    -H "Authorization: Bearer $RECETTE_JWT" -H 'Content-Type: application/ld+json' \
    -d "{\"sourceUrl\":\"$1\",\"startDate\":\"$2\",\"endDate\":\"$3\",\"maxDistancePerDay\":$4,\"ebikeMode\":${5:-false}}" \
    | json 'd=json.load(sys.stdin);print(d.get("id",""))'
}

echo "==> Creating trips from the provided Komoot links"
# Entre Sensée et Escaut (zone Lille -> also re-routing test); intermediate, camping
T1=$(create_trip "https://www.komoot.com/fr-fr/tour/2795080048" 2026-05-01 2026-05-03 70)
# Beginner, ~40 km/day, lodging
T2=$(create_trip "https://www.komoot.com/fr-fr/tour/2888900113" 2026-05-14 2026-05-17 40)
# Collection, intermediate, camping
T3=$(create_trip "https://www.komoot.com/fr-fr/collection/4226094/-cap-sur-la-cote-weekend-baroudeurs" 2026-05-08 2026-05-10 60)

echo
echo "RECETTE_JWT=$RECETTE_JWT"
echo "RECETTE_TRIP_IDS=$T1 $T2 $T3"
echo
echo "Note: trip computation is async (Komoot fetch + pacing + OSM/weather). Poll"
echo "  $CURL -H \"Authorization: Bearer \$RECETTE_JWT\" $BASE_URL/trips/<id>/detail"
echo "until stages appear. Re-routing (POI/accommodation) needs \`make provision\` +"
echo "the routing profile. GPX fallback: POST $BASE_URL/trips/gpx-upload (multipart gpxFile)."
