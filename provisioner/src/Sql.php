<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * The SQL quoting the provisioner splices into its psql statements, in one place so a
 * change to the strategy cannot be applied to one importer and forgotten in another.
 */
final class Sql
{
    /**
     * Single-quoted SQL literal. Every value reaching this today is an internal constant,
     * a slug already resolved against {@see GeofabrikRegionRegistry} or an operator-supplied
     * override row, so this guards the boundary rather than sanitising untrusted input.
     */
    public static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Per-zone schema name, e.g. `osm_staging_nord_pas_de_calais`: derived from the slug,
     * never configured, so two runs on different zones can never collide on a shared name.
     */
    public static function zoneSchema(string $prefix, string $zoneSlug): string
    {
        return $prefix.'_'.preg_replace('/[^a-z0-9]+/', '_', strtolower($zoneSlug));
    }
}
