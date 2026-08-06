<?php

declare(strict_types=1);

namespace App\InRide;

/**
 * In-ride intent categories served from the local-first Tier-1 index (ADR-040).
 *
 * Widens the four legacy intents (water/shelter/food/mechanic) to the eight
 * actionable buckets the index already holds: resupply (the shopping half of
 * osm.pois), health services, railway stations and e-bike charging points
 * (#927/#928). Each case drives {@see InRidePoiRepository}: the target osm.*
 * table, the KNN candidate cap, whether an unnamed row is actionable, and the
 * default search radius.
 */
enum InRidePoiCategory: string
{
    case WATER = 'water';
    case SHELTER = 'shelter';
    case FOOD = 'food';
    case RESUPPLY = 'resupply';
    case MECHANIC = 'mechanic';
    case HEALTH = 'health';
    case TRAIN = 'train';
    case CHARGING = 'charging';

    // Floor lowered from 2000 to 1000 m (#930): a rider in a village wants the
    // fountain on the next street, not one kilometre out.
    public const int MIN_RADIUS_METERS = 1_000;

    public const int MAX_RADIUS_METERS = 20_000;

    /**
     * Default reach when the caller sends none: immediate needs (water, cover,
     * a meal) stay close; resupply and health widen; a bike shop, a station or a
     * charger is worth a longer detour because they are sparse.
     */
    public function defaultRadiusMeters(): int
    {
        return match ($this) {
            self::WATER, self::SHELTER, self::FOOD => 3_000,
            self::RESUPPLY, self::HEALTH => 5_000,
            self::MECHANIC, self::TRAIN, self::CHARGING => 10_000,
        };
    }

    /**
     * Category default when null, otherwise the requested radius clamped to
     * [MIN_RADIUS_METERS, MAX_RADIUS_METERS].
     */
    public function clampRadius(?int $requested): int
    {
        if (null === $requested) {
            return $this->defaultRadiusMeters();
        }

        return max(self::MIN_RADIUS_METERS, min(self::MAX_RADIUS_METERS, $requested));
    }

    /**
     * Buckets where an entry without a name is not actionable: you cannot tell a
     * rider to head for an unnamed restaurant, bike shop, pharmacy or station.
     * Water points, shelters and chargers stay useful nameless (a fountain, a
     * bus shelter, a charging post are found by position), so the name filter
     * drops down to SQL only for these.
     */
    public function requiresName(): bool
    {
        return match ($this) {
            self::FOOD, self::MECHANIC, self::HEALTH, self::TRAIN => true,
            default => false,
        };
    }

    /**
     * KNN candidate cap. 50 restored the old Overpass ceiling, but where an
     * opening-hours filter still runs in PHP after the read (the venues a rider
     * needs open now: food, resupply, health, a bike shop) the 50 nearest can
     * all be closed and leave an empty answer, and widening the radius does not
     * change which 50 are nearest. Those buckets fetch 200; the always-available
     * ones (water, shelter, station, charger) keep 50.
     */
    public function candidateLimit(): int
    {
        return match ($this) {
            self::FOOD, self::RESUPPLY, self::MECHANIC, self::HEALTH => 200,
            default => 50,
        };
    }
}
