#!/bin/sh
#
# Provisioner entrypoint (ADR-060).
#
# The reference index (osm/tourism) now lives on the shared read-only PG-référence,
# not the PG-app database. The API is read-only on it and never migrates it, so the
# provisioner — the only writer — owns its DDL. Before the first import we apply the
# idempotent reference schema (schema/reference_schema.sql) via psql, so the live
# osm/tourism tables exist for promotion. The file is the SAME one the API's
# reference Doctrine migration runs in test/CI, so the two executors cannot drift.
#
# Baked outside /app on purpose: the dev compose bind-mounts ./provisioner over /app,
# which would shadow anything copied there.
set -e

REFERENCE_SCHEMA="${REFERENCE_SCHEMA_PATH:-/opt/btp/reference_schema.sql}"

if [ -f "$REFERENCE_SCHEMA" ] && [ -n "${PGHOST:-}" ]; then
	echo "Applying reference schema ($REFERENCE_SCHEMA) to ${PGHOST}/${PGDATABASE:-}..." >&2
	psql -v ON_ERROR_STOP=1 -f "$REFERENCE_SCHEMA" >&2
fi

exec php -d memory_limit=512M bin/provision "$@"
