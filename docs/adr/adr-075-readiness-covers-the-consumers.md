# ADR-075 — Readiness covers the consumers, not just the servers

**Status:** accepted
**Date:** 2026-09-22
**Continues** [ADR-040](adr-040-local-first-reference-data-postgis.md) and
[ADR-060](adr-060-pg-split-app-reference.md), which established which dependencies may block
readiness; **applies** [ADR-041](adr-041-provisioner-resilience.md)'s withdrawn R5 as a general
rule.

## Context

Every meaningful write in this product answers 202 and delegates to a worker. `/api/health`
checked Postgres, PG-référence, Redis, Mercure and Valhalla — that is, every server, and not one
consumer. Nothing looked at the Messenger transport, the queue depth, the `failed` transport, or
whether anything was consuming at all.

So a dead worker produced a green probe while every trip sat at `pending` until the tracker
expired half an hour later. It is the most likely failure mode of the system and it was the only
one entirely invisible. `docs/runbooks/worker-stuck.md` had been describing the symptom for
months, with a diagnosis procedure that could not be triggered by anything.

The instrumentation that did exist was thrown away.
`AbstractTripMessageHandler::executeWithTracking()` times every computation and logs the duration
at `info`; production wraps every channel but `deprecation` in a `fingers_crossed` handler at
`action_level: error`, so that line — and the twenty-odd other deliberate info/warning sites —
never reached stderr unless an error happened to land in the same 50-record window.

## Decision

### The verdict is an absence, never a threshold

`deps.messenger` reports `workers_alive`, `queue_depth` and `failed_depth`. **Only
`workers_alive == 0` turns readiness red.**

This is ADR-041's R5 generalised. That rule — alert when the reference index goes stale — was
withdrawn (#877) because the threshold would have been red permanently, and the age was kept as
a reported number with no verdict. A queue-depth threshold is the same mistake: it fires when the
system is merely busy, which is when an operator most needs the probe to be trustworthy.

The rule extends one step further: **the live worker count is not compared to
`WORKER_REPLICAS` either.** One consumer alive out of two is the normal state during a rolling
restart, and during the hourly recycle that `--time-limit=3600` performs. An absence is
unambiguous; a shortfall is not.

`failed_depth` is worth reporting for its own reason: nothing consumes that transport — the
worker runs `messenger:consume async` alone — so it is a dead-letter counter, not a backlog.

### Liveness is a heartbeat, because Redis cannot answer

The obvious source is the consumer group, and it cannot be used. The transport DSN supplies only
the stream name, so `group` and `consumer` both keep Symfony's defaults and **every replica
registers under the same consumer name**: `XINFO CONSUMERS` reports one entry whatever the
replica count. Counting consumers would need a distinct name per replica, which Compose cannot
give without an entrypoint shim.

Each worker therefore writes its own member into a sorted set on `WorkerRunningEvent`, and the
probe counts the members scored inside a window.

**Beat every 10 s, window of 300 s** — and the width is the load-bearing choice.
`WorkerRunningEvent` fires after each processed message and once per idle loop, but **never
during a handler**: the HTTP clients allow 10 s per call with retries, and the scans iterate over
stages, so a single computation can hold a worker for minutes. A tight window would declare a
busy worker dead, which is worse than no check. A wide window also absorbs the hourly gap where
`--time-limit=3600` retires both replicas within seconds of each other.

The case that actually matters loses nothing to the width: when no worker has ever started,
there is no entry at all and the window never comes into play. That is the acceptance criterion
of #510, and it is detected immediately.

The listener swallows its own exceptions. An exception in a `WorkerRunningEvent` listener
propagates through `Worker::run()` and stops the consumer — observability would have created the
outage it exists to report. Redis being unreachable is already the `redis` dependency's job.

### The probe does not use `messenger.receiver_locator`

This is the paragraph for whoever is tempted to simplify it.

Reading a depth through the transport service would go through the transport's own `Connection`,
which memoises its `\Redis` handle. Under FrankenPHP worker mode that handle outlives a Redis
restart, ext-redis does not reconnect, and `/api/health` would answer 503 **permanently** —
exactly the failure `api/src/Health/RedisHealthClientFactory` was written to avoid, and which its
docblock already describes. The probe opens a fresh connection and reads `XINFO GROUPS` directly.

The price is that the stream and group names are constants in the controller rather than being
derived from the DSN. That coupling is cheap and visible; a permanent false 503 is neither.

The same choice makes the check testable: the test environment forces both Messenger DSNs to
`in-memory://`, and `InMemoryTransport` does not implement `MessageCountAwareInterface`. Since
the verdict reads only a sorted set in the real Redis the suite already talks to, no test
scaffolding is needed to prove that a stopped worker degrades readiness.

The heartbeat key is namespaced per environment, because `phpunit.dist.xml` overrides the
Messenger DSNs but not `REDIS_URL`: without the suffix the test suite would delete the real
workers' beats from a dev stack sharing that Redis, and register itself as a phantom worker.

### The deployment gate retries

Readiness now depends on something that starts *after* the web container is healthy, so the
smoke test retries its assertion the way the liveness probe above it already did. Failing a good
deployment on a ten-second race is worse than not checking; a deployment that genuinely leaves no
worker running still fails, which is the point.

The `worker` container keeps its `pgrep` healthcheck. Pointing it at `/api/health` would restart
every worker at once on a Redis or Valhalla incident.

### No metrics endpoint

The operational numbers are on `/api/health`, readable by any `curl`. A Prometheus endpoint would
be scraped by nothing — no environment is deployed — and #515/#516 already hold the Sentry span
work. What was missing was not a metrics system but a log channel: production now emits the `app`
channel at `info` alongside the `fingers_crossed` handler, so the durations that were always
measured finally arrive, carrying the `request_id` that `CorrelationIdProcessor` attaches.

## Consequences

Accepted gaps, so they are not rediscovered as bugs:

- The three handlers that do not extend `AbstractTripMessageHandler`
  (`AllEnrichmentsCompletedHandler`, `ResolveStageLabelsHandler`, `SendPushNotificationHandler`)
  remain untimed. Duplicating the chronometer for them is code for nobody until someone reads
  those durations.
- An `error` on the `app` channel is emitted twice, once by the new handler and once by the
  buffered flush. Removing `app` from `main` would have stripped application errors of the
  context preceding them; two JSON lines sharing a `request_id` is the cheaper side.
- `queue_depth` counts undelivered entries only. Work already delivered to a worker and not yet
  acknowledged is invisible to it — which is the right reading for "is anything waiting", and the
  wrong one for "how much is in flight".
- The heartbeat set carries a TTL and is therefore evictable under `volatile-lru`. It is rewritten
  every ten seconds and holds one member per replica, so it is the last thing the LRU would pick,
  but under sustained memory pressure a false "no workers" is possible.
