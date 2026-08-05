<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The countries the Valhalla routing graph was built from, and the containment invariant
 * ADR-049 §6 asserts against them: **the routing perimeter encompasses the reference
 * perimeter**, so a zone the graph does not cover is refused rather than opened into a
 * region that cannot be routed.
 *
 * The perimeter is read from the national extracts present in the routing volume, which
 * is where `.docker/valhalla/build-routing-graph.sh` downloads them and what
 * `valhalla_build_tiles` builds from ("rebuilds tiles from *every* extract present
 * there"). That makes it an observation of the graph's actual input rather than a second
 * list to keep in step — the failure mode the `ROUTING_SLUGS` comment in compose.yaml
 * warns about. The provisioner mounts the volume read-only.
 *
 * ADR-049 is explicit that this cannot be a geometric test: a clipped Geofabrik regional
 * extract yields no country polygon at all (#880 measured 0 of 12 level-2 relations), so
 * the invariant compares two explicit lists — the zone's country from
 * {@see GeofabrikRegionRegistry} against the slugs found here.
 *
 * A perimeter that cannot be observed (the volume is not mounted, e.g. in CI) is
 * reported as unknown and does not block: refusing every zone because the check itself
 * is unavailable would turn a missing mount into a provisioning outage. An *empty*
 * perimeter, by contrast, is observed and refuses — that is a machine with no graph yet.
 */
final readonly class RoutingPerimeter
{
    public const string DEFAULT_DIR = '/routing';

    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory psql process factory; shared with the caller so commands are captured in tests
     */
    public function __construct(
        private string $tilesDir = self::DEFAULT_DIR,
        ?\Closure $processFactory = null,
        private float $timeoutSeconds = 60.0,
    ) {
        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
    }

    public function isObservable(): bool
    {
        return is_dir($this->tilesDir);
    }

    /**
     * Country slugs the graph was built from, i.e. the national extracts in the volume.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        $slugs = [];
        foreach (glob($this->tilesDir.'/*-latest.osm.pbf') ?: [] as $path) {
            // A zero-byte file is a leftover mountpoint, not an extract: the build
            // script removes those before building, so they are not in the graph either.
            if (!is_file($path) || 0 === filesize($path)) {
                continue;
            }

            $slugs[] = basename($path, '-latest.osm.pbf');
        }

        sort($slugs);

        return $slugs;
    }

    public function covers(string $country): bool
    {
        return \in_array($country, $this->slugs(), true);
    }

    /**
     * Records the observed perimeter so /api/health can assert containment against the
     * registry without reaching outside the database. Deletes what is no longer present:
     * a country removed from the volume is no longer in the graph either.
     *
     * @throws ImportFailedException
     */
    public function record(): void
    {
        $slugs = $this->slugs();
        $values = [] === $slugs
            ? ''
            : implode(', ', array_map(
                static fn (string $slug): string => \sprintf("('%s', now())", str_replace("'", "''", $slug)),
                $slugs,
            ));

        $sql = 'CREATE SCHEMA IF NOT EXISTS osm; CREATE TABLE IF NOT EXISTS osm.routing_perimeter (slug text NOT NULL, observed_at timestamptz NOT NULL, PRIMARY KEY (slug));';
        $sql .= '' === $values
            ? ' DELETE FROM osm.routing_perimeter;'
            : \sprintf(
                ' INSERT INTO osm.routing_perimeter (slug, observed_at) VALUES %s ON CONFLICT (slug) DO UPDATE SET observed_at = excluded.observed_at; DELETE FROM osm.routing_perimeter WHERE slug NOT IN (%s);',
                $values,
                implode(', ', array_map(static fn (string $slug): string => "'".str_replace("'", "''", $slug)."'", $slugs)),
            );

        $process = ($this->processFactory)(['psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-c', $sql]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $processTimedOutException) {
            throw new ImportFailedException(\sprintf('psql record routing perimeter timed out after %.1fs', $this->timeoutSeconds), 0, $processTimedOutException);
        }

        if (!$process->isSuccessful()) {
            throw new ImportFailedException(\sprintf("psql record routing perimeter failed (exit %s).\nStderr: %s", (string) $process->getExitCode(), $process->getErrorOutput()));
        }
    }
}
