<?php

declare(strict_types=1);

namespace App\Osm;

/**
 * Translates the one-character `osm_type` osm2pgsql stores (`N`, `W`, `R`) into
 * the word openstreetmap.org uses in its object URLs (`node`, `way`,
 * `relation`), so a reader can build `https://www.openstreetmap.org/<type>/<id>`
 * without knowing the storage convention.
 */
final class OsmObjectType
{
    /** @var array<string, string> */
    private const array NAMES = ['N' => 'node', 'W' => 'way', 'R' => 'relation'];

    public static function fromChar(mixed $raw): ?string
    {
        return \is_string($raw) ? (self::NAMES[strtoupper(trim($raw))] ?? null) : null;
    }
}
