<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * Normalises a feed's event date to the `YYYY-MM-DD` a `date` column accepts, or null.
 *
 * Both event feeds publish ISO dates or datetimes ("2026-07-01T18:00:00+02:00"), but a
 * single free-text or impossible value ("prochainement", "2026-02-30") reaching the
 * `\copy` into a `date` column aborts the whole load, so it is dropped here instead.
 */
final class EventDate
{
    public static function normalize(?string $value): ?string
    {
        if (null === $value || 1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches)) {
            return null;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
            ? \sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3])
            : null;
    }
}
