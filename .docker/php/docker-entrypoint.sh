#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V >&2

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...' >&2
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q 'SELECT 1' 2>&1); do
			if [ $? -eq 255 ]; then
				# If the Doctrine command exits with 255, an unrecoverable error occurred
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left." >&2
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			echo 'The database is not up or not reachable:' >&2
			echo "$DATABASE_ERROR" >&2
			exit 1
		else
			echo 'The database is now ready and reachable' >&2
		fi

		if [ "$( find ./migrations -iname '*.php' -print -quit )" ]; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing >&2
		fi
	fi

	# Generate JWT keypair if not present
	if [ ! -f config/jwt/private.pem ]; then
		php bin/console lexik:jwt:generate-keypair --skip-if-exists >&2
	fi

	# And a SEPARATE one for the MCP authorization server (ADR-079). league ships no
	# generate-keypair command, and without these files every /oauth/* request 500s on a
	# missing key file — handled at boot, next to the JWT pair, rather than discovered in
	# the middle of an authorization flow. Unencrypted, like the dev JWT pair
	# (compose.dev.yaml sets both passphrases empty); prod gets its keys from Vault.
	if [ ! -f config/oauth/private.pem ]; then
		mkdir -p config/oauth
		openssl genpkey -algorithm RSA -out config/oauth/private.pem -pkeyopt rsa_keygen_bits:4096 >&2
		openssl rsa -pubout -in config/oauth/private.pem -out config/oauth/public.pem >&2
	fi

	setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var
	setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var

	echo 'PHP app ready!' >&2
fi

exec docker-php-entrypoint "$@"
