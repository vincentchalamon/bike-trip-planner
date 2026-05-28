# OSM cron scheduler: runs the provisioner in update mode every night and
# restarts the Valhalla container so it rebuilds its tiles from the new PBF.
#
# Uses supercronic (cron designed for containers, logs to stdout) and the
# official Docker CLI binary to orchestrate sibling containers via the
# host's Docker socket (bind-mounted by compose).
FROM docker:28-cli

ARG SUPERCRONIC_VERSION=v0.2.46
ARG SUPERCRONIC_SHA1SUM=5bcefed628e32adc08e32634db2d10e9230dbca0

SHELL ["/bin/ash", "-o", "pipefail", "-c"]

# hadolint ignore=DL3018
RUN apk add --no-cache curl tini flock \
    && curl -fsSLO "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-amd64" \
    && echo "${SUPERCRONIC_SHA1SUM}  supercronic-linux-amd64" | sha1sum -c - \
    && chmod +x supercronic-linux-amd64 \
    && mv supercronic-linux-amd64 /usr/local/bin/supercronic \
    && apk del curl

COPY .docker/osm-cron/crontab /etc/supercronic/crontab
COPY .docker/osm-cron/run-update.sh /usr/local/bin/run-update.sh
RUN chmod +x /usr/local/bin/run-update.sh

# OSM_CRON_SCHEDULE is read at container start and written into the crontab.
ENV OSM_CRON_SCHEDULE="0 3 * * *"

ENTRYPOINT ["/sbin/tini", "--"]
# Strip any newline / carriage return from OSM_CRON_SCHEDULE before writing it
# to the crontab, otherwise a malicious value could inject an additional cron
# entry. Use printf '%s' to treat the env var as literal data.
CMD ["sh", "-c", "printf '%s /usr/local/bin/run-update.sh\\n' \"$(printf '%s' \"${OSM_CRON_SCHEDULE}\" | tr -d '\\n\\r')\" > /etc/supercronic/crontab && exec supercronic /etc/supercronic/crontab"]
