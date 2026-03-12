<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Immutable value object representing a set of fixed opening slots for a POI category.
 *
 * Each slot is an [open, close] pair of decimal hours (e.g. 9.0 = 09:00, 13.5 = 13:30).
 * A null $slots value means "no filter" — the POI is always considered open.
 */
final readonly class FixedSchedule
{
    /**
     * @param list<array{open: float, close: float}>|null $slots null means no time filter (always open)
     */
    private function __construct(
        private ?array $slots,
    ) {
    }

    /**
     * Supermarkets, convenience stores, general/farm/greengrocer/butcher/deli shops.
     * Typical hours: 09:00–20:00.
     */
    public static function supermarket(): self
    {
        return new self([['open' => 9.0, 'close' => 20.0]]);
    }

    /**
     * Restaurants, cafes, bars, fast-food outlets.
     * Typical hours: 12:00–14:00 and 19:00–22:00.
     */
    public static function restaurant(): self
    {
        return new self([
            ['open' => 12.0, 'close' => 14.0],
            ['open' => 19.0, 'close' => 22.0],
        ]);
    }

    /**
     * Bakeries and pastry shops.
     * Typical hours: 07:00–13:00 and 15:00–19:00.
     */
    public static function bakery(): self
    {
        return new self([
            ['open' => 7.0, 'close' => 13.0],
            ['open' => 15.0, 'close' => 19.0],
        ]);
    }

    /**
     * Marketplaces, pharmacies, and any other category without time restrictions.
     */
    public static function noFilter(): self
    {
        return new self(null);
    }

    /**
     * Returns true if the POI is considered open at the given decimal hour.
     *
     * @param float $decimalHour e.g. 13.5 for 13:30
     */
    public function isOpenAt(float $decimalHour): bool
    {
        if (null === $this->slots) {
            return true;
        }

        return array_any($this->slots, fn (array $slot): bool => $decimalHour >= $slot['open'] && $decimalHour <= $slot['close']);
    }
}
