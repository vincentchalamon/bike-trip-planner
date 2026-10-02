<?php

declare(strict_types=1);

namespace App\Doctrine;

use MartinGeorgiev\Doctrine\DBAL\Types\Jsonb as BaseJsonb;

/**
 * The library's `jsonb` type, encoding with JSON_PRESERVE_ZERO_FRACTION.
 *
 * Without the flag a float `2.0` is written as `2` and decoded back as the int `2`, so a
 * column did not hand back what was written, and a strict comparison of a reread value (the
 * unit of work's change detection among them) saw a difference that was not one. Decoding is
 * the library's, unchanged: `2.0` already decodes as a float, and a row written before this
 * type still decodes its `2` as an int.
 */
final class Jsonb extends BaseJsonb
{
    protected function transformToPostgresJson(mixed $phpValue): string
    {
        try {
            return json_encode($phpValue, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException) {
            $this->throwInvalidJsonValueException($phpValue);
        }
    }
}
