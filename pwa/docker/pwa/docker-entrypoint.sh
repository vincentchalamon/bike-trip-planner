#!/bin/sh
set -e

# Required for healthcheck
apk add curl

if [ "$1" = 'node' ] || [ "$1" = 'npm' ]; then
	if [ -z "$(ls -A 'node_modules/' 2>/dev/null)" ]; then
		npm install
	fi

	# Display information about the current project
	# Or about an error in project initialization
	npm --version

	echo 'PWA app ready!'
fi

exec /usr/local/bin/docker-entrypoint.sh "$@"
