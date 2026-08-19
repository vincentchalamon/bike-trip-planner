<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

/**
 * The curated resupply suggestions for a stage (#1099): a handful of food/water
 * points anchored to the ride, replacing the raw corridor POI dump that used to
 * block the mobile trip-open parse. Built by {@see \App\Poi\ResupplyBuilder}.
 */
final readonly class Resupply
{
    /**
     * @param list<PointOfInterest> $foodAtLunch   up to 2 food shops near the estimated lunch stop
     * @param list<PointOfInterest> $foodAtArrival up to 2 food shops at the arrival
     */
    public function __construct(
        public array $foodAtLunch = [],
        public ?PointOfInterest $waterMorning = null,
        public ?PointOfInterest $waterAfternoon = null,
        public array $foodAtArrival = [],
    ) {
    }

    /**
     * Every suggestion as a flat list, deduplicated by coordinate, ordered
     * lunch → water → arrival. Feeds map markers and GPX/FIT waypoints, where the
     * lunch/arrival role does not matter.
     *
     * @return list<PointOfInterest>
     */
    public function all(): array
    {
        $pois = [];
        $seen = [];
        foreach ([...$this->foodAtLunch, $this->waterMorning, $this->waterAfternoon, ...$this->foodAtArrival] as $poi) {
            if (!$poi instanceof PointOfInterest) {
                continue;
            }

            $key = $poi->lat.','.$poi->lon;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $pois[] = $poi;
        }

        return $pois;
    }

    public function isEmpty(): bool
    {
        return [] === $this->foodAtLunch
            && !$this->waterMorning instanceof PointOfInterest
            && !$this->waterAfternoon instanceof PointOfInterest
            && [] === $this->foodAtArrival;
    }
}
