#syntax=docker/dockerfile:1


# Versions
FROM docker.io/node:26@sha256:926d6cafec97f338577041890465522f70fe74aa6fe4b021a4fd7f87a5996b25 AS node_upstream


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
# AI feature flag (recette #649): default-off so prod and the iso-prod recette
# build mask the whole AI surface (generation assistant, in-ride chat, stage
# briefings + trip overview, account provider config). Set to "true" only where
# AI must stay visible (CI E2E in ci.yml, dev in compose.dev.yaml). The code and
# endpoints are kept for a later re-enable; this is a single reversible switch.
ARG NEXT_PUBLIC_ENABLE_AI=false
# Test-only SSE injection hook (SEC-012): default-off so the real prod bundle
# tree-shakes it out. Set to "true" only where the mocked E2E suite runs (CI in
# ci.yml, dev in compose.dev.yaml) — the mocked tests run against this prod image.
ARG NEXT_PUBLIC_ENABLE_TEST_HOOKS=false
# Client-facing origins, inlined at build time. Empty API URL → the browser calls
# the API on the SAME origin it was served from (relative URLs), so the build
# works unchanged on https://localhost, in prod (PWA + API share the origin), and
# behind a tunnel (ngrok) for mobile testing. Empty Mercure URL → the client
# derives the hub from the current origin too (see use-mercure). Only the native
# Capacitor build sets NEXT_PUBLIC_MERCURE_URL to reach its remote hub.
ARG NEXT_PUBLIC_API_URL=
ARG NEXT_PUBLIC_MERCURE_URL=

COPY --link pwa/. .

# SENTRY_AUTH_TOKEN (source-map upload) is a real secret: mounted via BuildKit
# so it never lands in an image layer or the build cache. Absent → the build
# proceeds without source-map upload (Sentry plugin silently skips).
RUN --mount=type=secret,id=sentry_auth_token \
    SENTRY_AUTH_TOKEN="$(cat /run/secrets/sentry_auth_token 2>/dev/null || true)" \
    NEXT_PUBLIC_APP_RELEASE="${NEXT_PUBLIC_APP_RELEASE}" \
    NEXT_PUBLIC_APP_ENV="${NEXT_PUBLIC_APP_ENV}" \
    NEXT_PUBLIC_SENTRY_DSN="${NEXT_PUBLIC_SENTRY_DSN}" \
    NEXT_PUBLIC_ENABLE_AI="${NEXT_PUBLIC_ENABLE_AI}" \
    NEXT_PUBLIC_ENABLE_TEST_HOOKS="${NEXT_PUBLIC_ENABLE_TEST_HOOKS}" \
    NEXT_PUBLIC_API_URL="${NEXT_PUBLIC_API_URL}" \
    NEXT_PUBLIC_MERCURE_URL="${NEXT_PUBLIC_MERCURE_URL}" \
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
