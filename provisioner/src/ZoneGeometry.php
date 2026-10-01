<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;

/**
 * Whether a zone has a geometry in the registry to clip a national feed against.
 *
 * The feed importers check this before promoting: the promotion clips to that geometry,
 * so with none it would promote exactly nothing and report success, silently. That happens
 * when the OSM step failed before writing the registry row, and also when it succeeded but
 * its clipped extract yielded no boundary at all (#880). The precondition is the geometry,
 * not the OSM step's exit code: gating on the OSM outcome would make a failed OSM download
 * block a feed refresh for a zone that is already open, which is precisely the
 * cross-source coupling ADR-041 forbids.
 */
final readonly class ZoneGeometry
{
    public function __construct(
        private ProcessRunner $processes,
    ) {
    }

    /**
     * @param string $path scratch file the count is exported to
     *
     * @throws ImportFailedException
     */
    public function exists(string $path, string $zoneSlug): bool
    {
        $this->processes->psql(\sprintf(
            "\\copy (SELECT count(*) FROM osm.zones WHERE slug = %s AND geom IS NOT NULL) TO '%s'",
            Sql::literal($zoneSlug),
            $path,
        ), 'psql check zone geometry');

        $contents = is_file($path) ? file_get_contents($path) : false;

        return \is_string($contents) && 0 < (int) trim($contents);
    }
}
