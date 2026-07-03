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
fi

exec docker-php-entrypoint "$@"
