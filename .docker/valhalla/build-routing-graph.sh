#!/usr/bin/env bash
#
# One-shot Valhalla routing-graph build (issue #881).
#
# The routing dataset is national and is deliberately NOT the reference dataset
# imported into PostGIS by the provisioner: opening a reference region must never
# touch the routing graph, and rebuilding the routing graph must never wait for a
# reference import. See docs/runbooks/valhalla-routing-graph.md.
#
# What this does, then exits:
#   1. downloads every missing national extract listed in $ROUTING_SLUGS into the
#      routing volume (/custom_files);
#   2. rebuilds tiles + elevation + admin + timezone databases from *every*
#      extract present there — the gis-ops image cannot add a country to an
#      existing graph, so a whole-perimeter rebuild is the only correct option;
#   3. exits (serve_tiles=False), leaving the `valhalla` service to serve the
#      result.
set -euo pipefail

: "${ROUTING_SLUGS:?ROUTING_SLUGS must list the Geofabrik country slugs to build, e.g. \"france\"}"

CUSTOM_FILES="${CUSTOM_FILES:-/custom_files}"
GEOFABRIK_BASE_URL="${GEOFABRIK_BASE_URL:-https://download.geofabrik.de/europe}"

# /custom_files is a root-owned named volume while the image runs as uid 59999,
# so writes go through sudo exactly like the image's own run.sh does.
as_root() {
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  else
    sudo -E "$@"
  fi
}

# Before #881 the volume carried a zero-byte `default.osm.pbf` — the mountpoint
# Docker created for the file bind-mount that has since been removed. The build
# globs /custom_files/*.pbf, and an empty extract aborts it.
for leftover in "$CUSTOM_FILES"/*.pbf; do
  if [ -f "$leftover" ] && [ ! -s "$leftover" ]; then
    echo "INFO: removing empty leftover extract ${leftover}"
    as_root rm -f "$leftover"
  fi
done

for slug in $ROUTING_SLUGS; do
  case "$slug" in
    *[!a-z0-9-]* | '')
      echo "ERROR: invalid routing slug '${slug}' (expected a Geofabrik country slug such as france)" >&2
      exit 1
      ;;
  esac

  target="${CUSTOM_FILES}/${slug}-latest.osm.pbf"
  if [ -s "$target" ]; then
    echo "INFO: ${slug} already present at ${target}; delete it to force a re-download."
    continue
  fi

  echo "INFO: downloading ${GEOFABRIK_BASE_URL}/${slug}-latest.osm.pbf"
  as_root curl --location --fail --show-error --retry 3 --retry-delay 5 \
    -o "${target}.tmp" "${GEOFABRIK_BASE_URL}/${slug}-latest.osm.pbf"
  as_root mv "${target}.tmp" "$target"
done

echo "INFO: routing perimeter about to be built:"
ls -lh "$CUSTOM_FILES"/*.pbf

# force_rebuild pulls every extract present in the volume into a single graph and
# repacks valhalla_tiles.tar, so the `valhalla` service only ever mmaps it.
export force_rebuild="True"
export serve_tiles="False"

exec /valhalla/scripts/run.sh build_tiles
