<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * PostgreSQL text COPY format, shared by every COPY file the provisioner writes:
 * tab-separated, `\N` for NULL, backslash-escaped, so no field can split or break a row
 * and abort the load under `ON_ERROR_STOP=1`.
 */
final class CopyWriter
{
    public static function value(string|int|float|null $value): string
    {
        if (null === $value) {
            return '\N';
        }

        $string = \is_string($value) ? $value : (string) $value;

        return str_replace(['\\', "\t", "\n", "\r"], ['\\\\', '\\t', '\\n', '\\r'], $string);
    }

    /**
     * @param list<string|int|float|null> $values
     */
    public static function line(array $values): string
    {
        return implode("\t", array_map(self::value(...), $values))."\n";
    }

    /**
     * EWKT, which PostGIS parses on input into a `geometry(Point, 4326)` column.
     */
    public static function point(float $lat, float $lon): string
    {
        return \sprintf('SRID=4326;POINT(%.7F %.7F)', $lon, $lat);
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function json(array $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
