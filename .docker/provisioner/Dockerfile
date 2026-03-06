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
