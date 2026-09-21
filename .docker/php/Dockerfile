#syntax=docker/dockerfile:1

# Adapted from https://github.com/dunglas/symfony-docker


# Versions
# Runtime and builder MUST stay on the same FrankenPHP release: the binary built
# below links against the builder's libphp, so a PHP patch mismatch between the
# two images breaks the dynamic link at boot. Both digests are FrankenPHP v1.12.7
# / PHP 8.5.10 — bump them together, never one alone.
FROM docker.io/dunglas/frankenphp:1-php8.5@sha256:8c11fc8030f12123470ae23a301e553718eb93034589de50f5a3177bd2686b16 AS frankenphp_upstream
FROM docker.io/dunglas/frankenphp:1-builder-php8.5@sha256:f2fb7fe771514a98839326789c73e9cab53aa4c5d7258c1a658cda50fa557405 AS frankenphp_builder_upstream


# The different stages of this Dockerfile are meant to be built into separate images
# https://docs.docker.com/develop/develop-images/multistage-build/#stop-at-a-specific-build-stage
# https://docs.docker.com/compose/compose-file/#target


# Custom FrankenPHP binary (ADR-037 addendum).
#
# The upstream image pins dunglas/mercure/caddy v0.24.2, which speaks the Mercure
# 0.x protocol only. This app speaks Mercure 1.0 (OAuth 2.0 access tokens,
# match/match_urlpattern), so the hub module has to be rebuilt at v1.0.0.
#
# Two things make that possible here and nowhere else:
#   - mercure/caddy v1.0.0 requires Caddy v2.11.4, exactly the version the
#     upstream image already embeds, so no other module has to move;
#   - it declares `go 1.27` while the builder image ships Go 1.26 with
#     GOTOOLCHAIN=local, hence the GOTOOLCHAIN=auto below, which lets Go fetch
#     the 1.27 toolchain on demand.
#
# frankenphp itself is taken from the sources vendored in the builder image
# (/go/src/app) rather than from the module proxy, so the binary is built against
# the very libphp it will be shipped with.
FROM frankenphp_builder_upstream AS frankenphp_builder

# Go 1.26 cannot build a `go 1.27` module under GOTOOLCHAIN=local.
ENV GOTOOLCHAIN=auto

# CGO_ENABLED=0: the builder image exports CGO_CFLAGS/CGO_LDFLAGS pointing at
# libphp, which a plain `go install` inherits and then fails to link against
# ("unknown symbol stderr in pcrel"). xcaddy itself needs no cgo; only the
# frankenphp build below does, and it sets the flags explicitly.
RUN CGO_ENABLED=0 go install github.com/caddyserver/xcaddy/cmd/xcaddy@v0.4.7

RUN set -eux; \
	CGO_ENABLED=1 \
	XCADDY_GO_BUILD_FLAGS="-ldflags='-w -s' -tags=nobadger,nomysql,nopgx" \
	CGO_CFLAGS="$(php-config --includes)" \
	CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
	"$(go env GOPATH)/bin/xcaddy" build \
		--output /usr/local/bin/frankenphp \
		--with github.com/dunglas/frankenphp=/go/src/app \
		--with github.com/dunglas/frankenphp/caddy=/go/src/app/caddy \
		--with github.com/dunglas/mercure/caddy@v1.0.0 \
		--with github.com/dunglas/vulcain/caddy \
		--with github.com/dunglas/caddy-cbrotli; \
	/usr/local/bin/frankenphp build-info > /tmp/build-info.txt; \
	grep -q 'dunglas/mercure/caddy\s*v1\.0\.0' /tmp/build-info.txt; \
	rm /tmp/build-info.txt


# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

# Mercure 1.0 hub (see the frankenphp_builder stage above).
COPY --from=frankenphp_builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

# persistent / runtime deps
# hadolint ignore=DL3008
RUN apt-get update && apt-get install --no-install-recommends -y \
	acl \
	file \
	gettext \
	git \
	procps \
	zip \
	&& rm -rf /var/lib/apt/lists/*

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN set -eux; \
	install-php-extensions \
		@composer \
		apcu \
		bcmath \
		intl \
		opcache \
		zip \
	;

###> recipes ###
###> doctrine/doctrine-bundle ###
RUN set -eux; \
	install-php-extensions pdo_pgsql redis xmlreader
###< doctrine/doctrine-bundle ###
###< recipes ###

COPY --link .docker/php/conf.d/10-app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY --link --chmod=755 .docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link .docker/php/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

# Exec form (no shell): `curl -f` already exits non-zero on an error status, so
# the previous `|| exit 1` was redundant (hadolint DL3025).
HEALTHCHECK --start-period=60s CMD ["curl", "-f", "http://localhost:2019/metrics"]
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch
VOLUME /app/var/

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN set -eux; \
	install-php-extensions \
		xdebug \
	;

COPY --link .docker/php/conf.d/20-app.dev.ini $PHP_INI_DIR/conf.d/zz-app.dev.ini

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod
# Default to running Doctrine migrations at boot in prod (see ADR-032).
ENV MIGRATIONS_ON_BOOT=true

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link .docker/php/conf.d/20-app.prod.ini $PHP_INI_DIR/conf.d/zz-app.prod.ini
COPY --link --chmod=755 .docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# prevent the reinstallation of vendors at every changes in the source code
COPY --link api/composer.* api/symfony.* ./
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link api/. ./
RUN rm -Rf .docker/php/

RUN set -eux; \
	mkdir -p var/cache var/log; \
	composer dump-autoload --classmap-authoritative --no-dev; \
	composer dump-env prod; \
	composer run-script --no-dev post-install-cmd; \
	chmod +x bin/console; sync; \
