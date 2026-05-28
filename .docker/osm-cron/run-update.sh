#!/bin/sh
# OSM nightly refresh orchestrator.
#
# 1. Runs the provisioner image in non-interactive mode against the persisted
#    region selection (see #477). The OSM data directory is shared with the
#    Valhalla container and mounted into the sibling provisioner container we
#    spawn via the host Docker socket.
# 2. Restarts the Valhalla container (identified by Compose labels) so the
#    GIS-Ops image rebuilds its tiles from the new PBF on startup.
#
# Designed to be invoked by supercronic in the osm-cron container.

set -euo pipefail

log() {
    printf '[osm-cron] %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

# Concurrency guard — a long-running provisioner must not overlap with the
# next scheduled fire, otherwise two sibling containers would write to the
# same OSM data directory and corrupt the merged PBF. flock -n exits
# immediately when the lock is already held; -E 0 turns that into a benign
# skip (rc 0) so cron doesn't treat it as a failure.
LOCK_FILE="${OSM_CRON_LOCK_FILE:-/tmp/osm-cron.lock}"
if [ -z "${OSM_CRON_LOCKED:-}" ]; then
    export OSM_CRON_LOCKED=1
    exec flock -n -E 0 "${LOCK_FILE}" "$0" "$@"
fi

# Identify the current Compose project from our own labels so we target the
# Valhalla instance belonging to *this* stack (multi-tenant safe). Falls back
# to OSM_CRON_PROJECT if the label is unset.
PROJECT="${OSM_CRON_PROJECT:-$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$(hostname)" 2>/dev/null || true)}"

if [ -z "${PROJECT}" ]; then
    log "ERROR: unable to determine Compose project name (set OSM_CRON_PROJECT)"
    exit 1
fi

PROVISIONER_IMAGE="${OSM_CRON_PROVISIONER_IMAGE:-${PROJECT}-provisioner}"

log "project=${PROJECT} provisioner_image=${PROVISIONER_IMAGE}"

# Discover the Valhalla container in this project so we can both reuse its
# host-side mount for /custom_files (which contains the merged PBF and is the
# same directory the provisioner writes to) and restart it once the refresh
# completes.
VALHALLA_CID="$(docker ps \
    --filter "label=com.docker.compose.project=${PROJECT}" \
    --filter "label=com.docker.compose.service=valhalla" \
    --format '{{.ID}}' | head -n 1)"

if [ -z "${VALHALLA_CID}" ]; then
    log "ERROR: no running Valhalla container found for project ${PROJECT}"
    exit 1
fi

# Extract the host path bind-mounted at /custom_files/default.osm.pbf inside
# the Valhalla container — that's the same default.osm.pbf the provisioner
# writes to /data/default.osm.pbf. We mount its parent directory at /data in
# the sibling provisioner container.
OSM_DATA_HOST_PATH="${OSM_CRON_OSM_DATA_PATH:-$(docker inspect "${VALHALLA_CID}" \
    --format '{{ range .Mounts }}{{ if eq .Destination "/custom_files/default.osm.pbf" }}{{ .Source }}{{ end }}{{ end }}' \
    | sed 's|/default\.osm\.pbf$||')}"

if [ -z "${OSM_DATA_HOST_PATH}" ]; then
    log "ERROR: unable to resolve host path of OSM data directory (set OSM_CRON_OSM_DATA_PATH)"
    exit 1
fi

log "osm_data_host_path=${OSM_DATA_HOST_PATH}"

log "running provisioner update"
docker run --rm \
    --label "com.docker.compose.project=${PROJECT}" \
    -v "${OSM_DATA_HOST_PATH}:/data" \
    "${PROVISIONER_IMAGE}" \
    --no-interaction

log "provisioner update finished, restarting Valhalla container ${VALHALLA_CID}"
docker restart "${VALHALLA_CID}"
log "Valhalla restarted, tiles will rebuild from the new PBF on startup"
