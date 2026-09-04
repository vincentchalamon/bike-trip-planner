<?php

declare(strict_types=1);

namespace App\Weather;

/**
 * A location's raw hourly series over the requested date range, in its own local
 * time. Cached per (location, day) and derived into a WeatherForecast per stage.
 */
final readonly class RawForecast
{
    /**
     * @param list<RawHourlySlot> $slots ordered by local time
     */
    public function __construct(
        public \DateTimeZone $timezone,
        public array $slots,
    ) {
    }

    /**
     * The slots falling on the given local calendar date.
     *
     * @return list<RawHourlySlot>
     */
    public function slotsForDate(string $localDate): array
    {
        return array_values(array_filter(
            $this->slots,
            static fn (RawHourlySlot $s): bool => $s->time->format('Y-m-d') === $localDate,
        ));
    }
}
