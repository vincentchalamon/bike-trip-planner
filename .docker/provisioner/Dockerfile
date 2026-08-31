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
ADD --checksum=sha256:7c133ae4b9490d912287188c62ea570729cfa74f0ea357e4be672ce696b4aa29 \
    --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.11.12/install-php-extensions /usr/local/bin/
RUN install-php-extensions xdebug zip
ENV XDEBUG_MODE=off

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Reference schema + entrypoint (ADR-060). Baked under /opt/btp so the dev bind
# mount of ./provisioner over /app cannot shadow it. The entrypoint applies the
# schema to PG-référence before running bin/provision.
COPY api/migrations/schema/reference_schema.sql /opt/btp/reference_schema.sql
COPY .docker/provisioner/docker-entrypoint.sh /usr/local/bin/provisioner-entrypoint.sh
RUN chmod +x /usr/local/bin/provisioner-entrypoint.sh

# Cap PHP memory so a regression in the streaming/enrichment paths fails with a
# clean PHP fatal instead of a silent container OOM-kill (ADR-041 R4). osm2pgsql
# and psql run as separate processes, bounded by --cache and the DB config.
ENTRYPOINT ["/usr/local/bin/provisioner-entrypoint.sh"]

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
ADD --checksum=sha256:7c133ae4b9490d912287188c62ea570729cfa74f0ea357e4be672ce696b4aa29 \
    --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.11.12/install-php-extensions /usr/local/bin/
RUN install-php-extensions zip

WORKDIR /app
COPY --from=deps /app/ ./

# Reference schema + entrypoint (ADR-060): applied to PG-référence before the first
# import so the osm/tourism live tables exist. Same file the API reference migration
# runs in test/CI.
COPY api/migrations/schema/reference_schema.sql /opt/btp/reference_schema.sql
COPY .docker/provisioner/docker-entrypoint.sh /usr/local/bin/provisioner-entrypoint.sh
RUN chmod +x /usr/local/bin/provisioner-entrypoint.sh

# Cap PHP memory so a regression in the streaming/enrichment paths fails with a
# clean PHP fatal instead of a silent container OOM-kill (ADR-041 R4). osm2pgsql
# and psql run as separate processes, bounded by --cache and the DB config.
ENTRYPOINT ["/usr/local/bin/provisioner-entrypoint.sh"]
