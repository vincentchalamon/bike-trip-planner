#!/usr/bin/env bash
#
# Restore test of a PG-app backup (ADR-062): fetch a dump, decrypt it, restore it
# into a throwaway PostGIS container, check that it holds users. Nothing is
# written to the prod database and no plaintext dump touches the disk.
#
# Runs where the age PRIVATE key is, i.e. NOT on the VM (the VM only holds the
# public recipient, by design): the operator workstation, on demand
# (`make backup-restore-test`) or from a local timer / cron.
#
# Usage: backup-restore-test.sh [<tier>/<object>]   (default: newest daily dump)
#
# Environment:
#   AGE_IDENTITY        required, path to the age private key file
#   BACKUP_REMOTE       rclone remote + bucket, default b2:btp-backups
#   RCLONE_CONFIG       optional, a read-only key is enough
#   PG_IMAGE            default postgis/postgis:18-3.6-alpine (PG-app's image)
#   MIN_USERS           minimum rows expected in "user", default 1
#   HEARTBEAT_URL       optional, pinged (GET) after a successful test
set -euo pipefail

: "${AGE_IDENTITY:?AGE_IDENTITY must point at the age private key file}"
remote="${BACKUP_REMOTE:-b2:btp-backups}"
image="${PG_IMAGE:-postgis/postgis:18-3.6-alpine}"
min_users="${MIN_USERS:-1}"
object="${1:-}"

if [ -z "$object" ]; then
  latest="$(rclone lsf --files-only "${remote}/daily" | sort | tail -n 1)"
  [ -n "$latest" ] || { echo "restore-test: no dump under ${remote}/daily" >&2; exit 1; }
  object="daily/${latest}"
fi

tmp="$(mktemp -d)"
container="btp-restore-test-$$"
cleanup() {
  docker rm -f "$container" >/dev/null 2>&1 || true
  rm -f "${tmp}/dump.age"
  rmdir "$tmp" 2>/dev/null || true
}
trap cleanup EXIT

echo "restore-test: fetching ${remote}/${object}"
rclone copyto "${remote}/${object}" "${tmp}/dump.age"

docker run -d --name "$container" --tmpfs /var/lib/postgresql \
  -e POSTGRES_PASSWORD=restore-test "$image" >/dev/null

ready=0
for _ in $(seq 1 60); do
  # Over TCP: the image's init-time server listens on the socket only, so this
  # waits for the final server rather than the one it restarts.
  if docker exec "$container" pg_isready -h 127.0.0.1 -U postgres -q 2>/dev/null; then
    ready=1
    break
  fi
  sleep 1
done
[ "$ready" = 1 ] || { echo "restore-test: throwaway postgres did not start" >&2; exit 1; }

# From template0: the image's init scripts install PostGIS (and its tiger /
# topology schemas) into template1, which the dump would then collide with.
docker exec "$container" createdb -U postgres -T template0 restore

# Decrypt straight into pg_restore: a dump age cannot open, or that pg_restore
# cannot list, fails here.
age -d -i "$AGE_IDENTITY" "${tmp}/dump.age" \
  | docker exec -i "$container" pg_restore --list >/dev/null
age -d -i "$AGE_IDENTITY" "${tmp}/dump.age" \
  | docker exec -i "$container" pg_restore --exit-on-error --no-owner --no-privileges \
      -U postgres -d restore

tables="$(docker exec "$container" psql -U postgres -d restore -tAc \
  "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'")"
users="$(docker exec "$container" psql -U postgres -d restore -tAc 'SELECT count(*) FROM "user"')"
echo "restore-test: ${object}: ${tables} tables, ${users} users"

if [ "$users" -lt "$min_users" ]; then
  echo "restore-test: expected at least ${min_users} users" >&2
  exit 1
fi

if [ -n "${HEARTBEAT_URL:-}" ]; then
  curl -fsS -m 10 --retry 3 -o /dev/null "$HEARTBEAT_URL" \
    || echo "restore-test: heartbeat ping failed" >&2
fi
echo "restore-test: OK"
