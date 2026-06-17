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

# osm2pgsql + postgresql-client (psql) drive the Tier-1 PostGIS import (ADR-040).
# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    	osmium-tool \
    	osm2pgsql \
    	postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# zip drives the DataTourisme flux import (ext-zip → ZipArchive). xdebug enables
# `make coverage`/`coverage-ci` on the provisioner leg (same driver as the php dev
# image), off by default so normal `make test-php` is unaffected; the coverage
# targets set XDEBUG_MODE=coverage per run.
ADD --checksum=sha256:ca45e43f4299997f3cc78459eb7cc29c125281db8779f99209dc3fe3298fd117 \
    --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.11.1/install-php-extensions /usr/local/bin/
RUN install-php-extensions xdebug zip
ENV XDEBUG_MODE=off

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Cap PHP memory so a regression in the streaming/enrichment paths fails with a
# clean PHP fatal instead of a silent container OOM-kill (ADR-041 R4). osm2pgsql
# and psql run as separate processes, bounded by --cache and the DB config.
ENTRYPOINT ["php", "-d", "memory_limit=512M", "bin/provision"]

# Stage 3: Production
FROM php:8.5-cli-bookworm AS prod

# osm2pgsql + postgresql-client (psql) drive the Tier-1 PostGIS import (ADR-040).
# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    	osmium-tool \
    	osm2pgsql \
    	postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# zip drives the DataTourisme flux import (ext-zip → ZipArchive).
ADD --checksum=sha256:ca45e43f4299997f3cc78459eb7cc29c125281db8779f99209dc3fe3298fd117 \
    --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.11.1/install-php-extensions /usr/local/bin/
RUN install-php-extensions zip

WORKDIR /app
COPY --from=deps /app/ ./

# Cap PHP memory so a regression in the streaming/enrichment paths fails with a
# clean PHP fatal instead of a silent container OOM-kill (ADR-041 R4). osm2pgsql
# and psql run as separate processes, bounded by --cache and the DB config.
ENTRYPOINT ["php", "-d", "memory_limit=512M", "bin/provision"]
