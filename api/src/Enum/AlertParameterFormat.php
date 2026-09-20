<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a raw alert parameter becomes readable text.
 *
 * Producers persist the number and name the format; {@see \App\Alert\AlertRenderer} applies
 * it in the reader's locale (ADR-069). Splitting the two is what lets `2400` render as
 * "2,4 km" to one reader and "2.4 km" to the next, and reach an API client as the metres it
 * actually is.
 */
enum AlertParameterFormat: string
{
    /** Metres below a kilometre, kilometres above: "480 m", "2.4 km". */
    case DISTANCE = 'distance';

    /** Always kilometres, whatever the magnitude — where the message frames the value as one. */
    case DISTANCE_KM = 'distance_km';

    /** A bare number with the locale's decimal separator, at most one decimal. */
    case DECIMAL = 'decimal';

    /** Same, but always exactly one decimal: a gradient reads "8.0%", never "8%". */
    case DECIMAL_ONE = 'decimal_1';

    /**
     * A list of raw OSM surface values ("gravel", "compacted"), each named in the reader's
     * language and joined. Values the catalogue has no entry for fall back to "unknown".
     */
    case SURFACE_LIST = 'surface_list';

    /** A raw POI category, named in the reader's language ("museum" -> "Museum"). */
    case POI_LABEL = 'poi_label';

    /** Same category, in the form that stands in for a missing name ("a museum"). */
    case POI_DISPLAY_NAME = 'poi_display_name';

    /** @var list<string> */
    public const array VALUES = [
        self::DISTANCE->value,
        self::DISTANCE_KM->value,
        self::DECIMAL->value,
        self::DECIMAL_ONE->value,
        self::SURFACE_LIST->value,
        self::POI_LABEL->value,
        self::POI_DISPLAY_NAME->value,
    ];
}
