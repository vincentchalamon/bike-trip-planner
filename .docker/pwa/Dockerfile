#syntax=docker/dockerfile:1


# Versions
FROM docker.io/node:25@sha256:48ef22fa30108ed7dd930c41e571f7d7ea8166e81ead4184bbe899d4398ac275 AS node_upstream


# Base stage for dev and build
FROM node_upstream AS base

WORKDIR /srv/app

# Next.js collects completely anonymous telemetry data about general usage.
# Learn more here: https://nextjs.org/telemetry
# Delete the following line in case you want to enable telemetry during dev and build.
ENV NEXT_TELEMETRY_DISABLED=1


# Development image
FROM base AS dev

EXPOSE 3000
ENV PORT=3000
ENV HOSTNAME=localhost

CMD ["sh", "-c", "npm install; npm run dev"]


FROM base AS deps

COPY --link pwa/package.json pwa/package-lock.json ./
RUN npm ci


FROM deps AS builder

# Public client config inlined into the bundle by Next.js at build time
# (NEXT_PUBLIC_* are not secrets — the DSN ships in every error payload). These
# MUST be set during `next build`, otherwise Sentry.init runs with an undefined
# DSN (client capture disabled) and the release tag won't match uploaded maps.
ARG NEXT_PUBLIC_APP_RELEASE
ARG NEXT_PUBLIC_APP_ENV=production
ARG NEXT_PUBLIC_SENTRY_DSN

COPY --link pwa/. .

# SENTRY_AUTH_TOKEN (source-map upload) is a real secret: mounted via BuildKit
# so it never lands in an image layer or the build cache. Absent → the build
# proceeds without source-map upload (Sentry plugin silently skips).
RUN --mount=type=secret,id=sentry_auth_token \
    SENTRY_AUTH_TOKEN="$(cat /run/secrets/sentry_auth_token 2>/dev/null || true)" \
    NEXT_PUBLIC_APP_RELEASE="${NEXT_PUBLIC_APP_RELEASE}" \
    NEXT_PUBLIC_APP_ENV="${NEXT_PUBLIC_APP_ENV}" \
    NEXT_PUBLIC_SENTRY_DSN="${NEXT_PUBLIC_SENTRY_DSN}" \
    npm run build


# Production image, copy all the files and run next
FROM node_upstream AS prod

WORKDIR /srv/app

ENV NODE_ENV=production
# Delete the following line in case you want to enable telemetry during runtime.
ENV NEXT_TELEMETRY_DISABLED=1

RUN addgroup --system --gid 1001 nodejs; \
	adduser --system --uid 1001 nextjs

COPY --from=builder --link /srv/app/public ./public

# Set the correct permission for prerender cache
RUN mkdir .next; \
	chown nextjs:nodejs .next

# Automatically leverage output traces to reduce image size
# https://nextjs.org/docs/advanced-features/output-file-tracing
COPY --from=builder --link --chown=1001:1001 /srv/app/.next/standalone ./
COPY --from=builder --link --chown=1001:1001 /srv/app/.next/static ./.next/static

USER nextjs

EXPOSE 3000

ENV PORT=3000
ENV HOSTNAME="0.0.0.0"

CMD ["node", "server.js"]
