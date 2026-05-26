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

if [ "${MIGRATIONS_ON_BOOT:-false}" = "true" ]; then
	echo "Running Doctrine migrations..." >&2
	bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration >&2
fi

exec docker-php-entrypoint "$@"
