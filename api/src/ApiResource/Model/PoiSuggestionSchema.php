<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

/**
 * Single source of truth for the OpenAPI schema describing one item in a
 * {@see \App\InRide\PoiSuggestion}-shaped payload.
 *
 * Both {@see \App\ApiResource\TripChatResponse::$pois} and
 * {@see \App\ApiResource\TripChatMessageResource::$pois} expose arrays of this
 * shape on the wire; sharing the constant ensures the OpenAPI export, the
 * generated TypeScript types and the runtime Zod schema on the PWA all derive
 * from the same field list.
 *
 * If `PoiSuggestion::toArray()` ever gains or renames a key, update this
 * constant and both API resources pick up the new schema automatically.
 */
final class PoiSuggestionSchema
{
    /**
     * OpenAPI 3.1 object schema for a single POI suggestion item.
     *
     * @var array<string, mixed>
     */
    public const array ITEM_SCHEMA = [
        'type' => 'object',
        'required' => ['name', 'category', 'lat', 'lon', 'distance_m', 'detour_m', 'deeplink'],
        'properties' => [
            'name' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'lat' => ['type' => 'number', 'format' => 'float'],
            'lon' => ['type' => 'number', 'format' => 'float'],
            'distance_m' => ['type' => 'number', 'format' => 'float'],
            'detour_m' => ['type' => 'number', 'format' => 'float'],
            'opening_hours_today' => ['type' => ['string', 'null']],
            'closes_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'phone' => ['type' => ['string', 'null']],
            'deeplink' => ['type' => 'string'],
            'warning' => ['type' => ['string', 'null']],
        ],
    ];
}
