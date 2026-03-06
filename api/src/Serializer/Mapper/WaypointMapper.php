<?php

declare(strict_types=1);

namespace App\Serializer\Mapper;

/**
 * Maps OSM categories to GPX symbols and FIT course point types.
 *
 * @see https://developer.garmin.com/fit/protocol/ — course_point_type
 */
final readonly class WaypointMapper
{
    // FIT Course Point type enum values
    public const int GENERIC = 0;

    public const int SUMMIT = 2;

    public const int FOOD = 6;

    public const int WATER = 7;

    public const int DANGER = 8;

    public const int FIRST_AID = 12;

    /** @var array<string, string> */
    private const array SYMBOL_MAP = [
        // Food & drink (amenity)
        'restaurant' => 'Restaurant',
        'cafe' => 'Restaurant',
        'bar' => 'Restaurant',
        'fast_food' => 'Restaurant',

        // Resupply (shop)
        'supermarket' => 'Shopping Center',
        'convenience' => 'Shopping Center',
        'bakery' => 'Shopping Center',
        'butcher' => 'Shopping Center',
        'pastry' => 'Shopping Center',
        'deli' => 'Shopping Center',
        'greengrocer' => 'Shopping Center',
        'general' => 'Shopping Center',
        'farm' => 'Shopping Center',
        'marketplace' => 'Shopping Center',

        // Health (amenity)
        'pharmacy' => 'Medical Facility',

        // Tourism
        'viewpoint' => 'Scenic Area',
        'attraction' => 'Museum',

        // Water
        'drinking_water' => 'Drinking Water',

        // Accommodation (tourism)
        'camp_site' => 'Campground',
        'hostel' => 'Lodge',
        'guest_house' => 'Lodge',
        'alpine_hut' => 'Lodge',
        'chalet' => 'Lodge',
        'hotel' => 'Hotel',
        'motel' => 'Hotel',
    ];

    private const string FALLBACK_SYMBOL = 'Flag, Blue';

    /** @var array<string, int> */
    private const array TYPE_MAP = [
        // Food & drink
        'restaurant' => self::FOOD,
        'cafe' => self::FOOD,
        'bar' => self::FOOD,
        'fast_food' => self::FOOD,

        // Resupply (mapped to Food — closest match for shops)
        'supermarket' => self::FOOD,
        'convenience' => self::FOOD,
        'bakery' => self::FOOD,
        'butcher' => self::FOOD,
        'pastry' => self::FOOD,
        'deli' => self::FOOD,
        'greengrocer' => self::FOOD,
        'general' => self::FOOD,
        'farm' => self::FOOD,
        'marketplace' => self::FOOD,

        // Health
        'pharmacy' => self::FIRST_AID,

        // Tourism
        'viewpoint' => self::SUMMIT,
        'attraction' => self::GENERIC,

        // Water
        'drinking_water' => self::WATER,

        // Accommodation
        'camp_site' => self::GENERIC,
        'hostel' => self::GENERIC,
        'guest_house' => self::GENERIC,
        'alpine_hut' => self::GENERIC,
        'chalet' => self::GENERIC,
        'hotel' => self::GENERIC,
        'motel' => self::GENERIC,
    ];

    public static function gpxSymbol(string $category): string
    {
        return self::SYMBOL_MAP[$category] ?? self::FALLBACK_SYMBOL;
    }

    public static function fitCoursePointType(string $category): int
    {
        return self::TYPE_MAP[$category] ?? self::GENERIC;
    }
}
