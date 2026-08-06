<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\ApiResource\Model\GeoPosition;
use App\InRide\InRidePoiCategory;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for `POST /trips/{id}/nearby-pois`.
 *
 * An unknown `category` is rejected by the denormalizer (NotNormalizableValue ->
 * 400) before validation runs, so the enum type carries the whitelist. The
 * position is sent in the body, never the query string, so the rider's GPS
 * coordinates never land in logs, the `Referer` header or the browser history.
 *
 * `radiusMeters` carries no validation range on purpose: an out-of-bounds value
 * is clamped to [MIN_RADIUS_METERS, MAX_RADIUS_METERS] by
 * {@see InRidePoiCategory::clampRadius()} rather than rejected, so a rider never
 * gets a 422 for asking too wide or too narrow.
 */
final class NearbyPoiSearchRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?InRidePoiCategory $category = null,
        #[Assert\NotNull]
        #[Assert\Valid]
        public ?GeoPosition $position = null,
        public ?int $radiusMeters = null,
        #[Assert\Range(min: 1)]
        public ?int $stageDay = null,
    ) {
    }
}
