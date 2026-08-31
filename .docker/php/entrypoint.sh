#!/bin/sh
#
# Production entrypoint for the frankenphp_prod image.
#
# Runs Doctrine migrations on container boot when MIGRATIONS_ON_BOOT=true (default in prod).
# Set MIGRATIONS_ON_BOOT=false to skip auto-migration if a migration is suspected
# to be problematic — then run migrations manually via `bin/console doctrine:migrations:migrate`.
#
# See docs/adr/adr-032-migrations-and-rollback-strategy.md
set -e

# Fail closed (SEC-004): the Mercure hub is internet-facing and verifies subscriber
# JWTs with this key, so booting prod on the public API Platform skeleton default
# would let anyone forge a token and read every trip's live stream. compose resolves
# MERCURE_JWT_KEY into MERCURE_JWT_SECRET, so we check the resolved container value.
# CI and local iso-prod (recette) provide a non-default key; real prod MUST set one.
case "${MERCURE_JWT_SECRET:-}" in
	'' | *'!ChangeThisMercureHubJWTSecretKey!'*)
		echo 'FATAL: MERCURE_JWT_KEY is unset or still the public skeleton default; refusing to boot (SEC-004). Set a strong MERCURE_JWT_KEY.' >&2
		exit 1
		;;
esac

# HS256 (Lcobucci JWT) rejects a key shorter than 256 bits at signing time. Left
# unchecked, a too-short-but-non-default key passes the guard above, boots "healthy",
# then 500s at runtime on every Mercure-token mint (e.g. GET /trips/{id}/detail).
# Fail closed at boot instead — MERCURE_JWT_SECRET is ASCII, so ${#..} is its byte length.
if [ "${#MERCURE_JWT_SECRET}" -lt 32 ]; then
	echo 'FATAL: MERCURE_JWT_KEY must be at least 32 bytes (256 bits) for HS256; refusing to boot (SEC-004).' >&2
	exit 1
fi

# Fail closed (SEC-003): REFRESH_TOKEN_ENC_KEY encrypts refresh tokens at rest.
# Unset, it falls back to the committed dev default in services.php, so the bearer
# credential would be "encrypted" under a key anyone with the source can read.
# Refuse to boot on the missing/default key. CI and iso-prod set a real one.
case "${REFRESH_TOKEN_ENC_KEY:-}" in
	'' | 'dev-only-refresh-token-encryption-key-change-in-prod')
		echo 'FATAL: REFRESH_TOKEN_ENC_KEY is unset or still the dev default; refusing to boot (SEC-003). Set a strong REFRESH_TOKEN_ENC_KEY.' >&2
		exit 1
		;;
esac

if [ "${MIGRATIONS_ON_BOOT:-false}" = "true" ]; then
	# Wait for the database to accept connections before migrating. The compose
	# healthcheck (pg_isready) can briefly report ready during Postgres' init
	# window while the server still refuses TCP connections, so we retry a real
	# query here. Without this, migrate fails with SQLSTATE[08006] and the php
	# container crash-loops, leaving dependent workers stuck in `created`.
	# Mirrors .docker/php/docker-entrypoint.sh (dev) and api-platform/demo.
	echo 'Waiting for database to be ready...' >&2
	ATTEMPTS_LEFT_TO_REACH_DATABASE=60
	until [ "$ATTEMPTS_LEFT_TO_REACH_DATABASE" -eq 0 ] || DATABASE_ERROR=$(bin/console dbal:run-sql -q 'SELECT 1' 2>&1); do
		if [ $? -eq 255 ]; then
			# Unrecoverable error (e.g. invalid DSN) — stop retrying.
			ATTEMPTS_LEFT_TO_REACH_DATABASE=0
			break
		fi
		sleep 1
		ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
		echo "Still waiting for database to be ready... $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left." >&2
	done

	if [ "$ATTEMPTS_LEFT_TO_REACH_DATABASE" -eq 0 ]; then
		echo 'The database is not up or not reachable:' >&2
		echo "$DATABASE_ERROR" >&2
		exit 1
	fi
	echo 'The database is now ready and reachable' >&2

	echo 'Running Doctrine migrations...' >&2
	bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration >&2

	# Reference schema on a single-database deployment (ADR-060). The public boot
	# migrate above owns only the `public` (PG-app) schema; the osm/tourism reference
	# schema is normally owned by the provisioner on a SEPARATE PG-référence. But dev,
	# CI, the E2E integration smoke and the recette all run one Postgres for both
	# connections and never launch the provisioner, so the reference tables (empty)
	# would be missing and any reference read — e.g. storeStages()'s on-cycle-network /
	# out-of-zone scans against osm.* during a plain GPX import — would 500.
	#
	# Detect that single-database case by REFERENCE_DATABASE_URL being unset or equal to
	# DATABASE_URL, and then also run the reference history (its own version table) on
	# the reference connection. On a real split (the two URLs differ) this is skipped:
	# the app stays read-only on the shared PG-référence, which the provisioner owns.
	# Separate `bin/console` invocation on purpose — two migrate configs in one process
	# is unreliable with the bundle (the second is ignored).
	if [ -z "${REFERENCE_DATABASE_URL:-}" ] || [ "${REFERENCE_DATABASE_URL}" = "${DATABASE_URL:-}" ]; then
		echo 'Single-database deployment: running reference migrations on the reference connection...' >&2
		bin/console doctrine:migrations:migrate --configuration=config/migrations_reference.php --conn reference --no-interaction --allow-no-migration >&2
	else
		echo 'Split deployment (REFERENCE_DATABASE_URL != DATABASE_URL): reference schema is owned by the provisioner, skipping.' >&2
	fi
fi

exec docker-php-entrypoint "$@"
