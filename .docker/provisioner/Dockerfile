# Stage 1: Composer dependencies
FROM php:8.5-cli-bookworm AS deps

# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    	git \
    	osmium-tool \
    	unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY provisioner/composer.json provisioner/composer.lock ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts
COPY provisioner/ ./
RUN composer dump-autoload --classmap-authoritative --no-dev

# Stage 2: Dev (sources bind-mounted via compose.yaml)
FROM php:8.5-cli-bookworm AS dev

# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    	osmium-tool \
    && rm -rf /var/lib/apt/lists/*

# xdebug enables `make coverage`/`coverage-ci` on the provisioner leg (same driver as
# the php dev image). Off by default so normal `make test-php` is unaffected; the
# coverage targets set XDEBUG_MODE=coverage per run.
ADD --checksum=sha256:ca45e43f4299997f3cc78459eb7cc29c125281db8779f99209dc3fe3298fd117 \
    --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.11.1/install-php-extensions /usr/local/bin/
RUN install-php-extensions xdebug
ENV XDEBUG_MODE=off

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENTRYPOINT ["php", "bin/provision"]

# Stage 3: Production
FROM php:8.5-cli-bookworm AS prod

# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    	osmium-tool \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=deps /app/ ./

ENTRYPOINT ["php", "bin/provision"]
