<?php

declare(strict_types=1);

namespace App\Format;

/**
 * Turns a distance in metres into a localised, human-readable string with its
 * unit: "480 m" below one kilometre, "43,9 km" above (fr) / "43.9 km" (en).
 */
final readonly class DistanceFormatter
{
    /** Above this magnitude, metres stop being readable. */
    private const float KILOMETRE_THRESHOLD_METERS = 1000.0;

    public function __construct(
        private DecimalFormatter $decimalFormatter,
    ) {
    }

    public function format(float $meters, string $locale): string
    {
        if (abs($meters) < self::KILOMETRE_THRESHOLD_METERS) {
            return $this->decimalFormatter->format($meters, $locale, 0, 0).' m';
        }

        return $this->formatKilometers($meters, $locale);
    }

    /**
     * Always kilometres, whatever the magnitude: used where the message frames
     * the value as a kilometre distance regardless of its size.
     */
    public function formatKilometers(float $meters, string $locale): string
    {
        return $this->decimalFormatter->format($meters / 1000, $locale, 0, 1).' km';
    }
}
