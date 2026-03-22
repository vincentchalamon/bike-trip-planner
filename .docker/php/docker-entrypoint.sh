#!/bin/sh
set -e

if [ "$1" = 'symfony' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V

	setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var
	setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var

	# Run pending Doctrine migrations
	if bin/console doctrine:migrations:migrate --no-interaction 2>/dev/null; then
		echo "✅ Doctrine migrations applied"
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
