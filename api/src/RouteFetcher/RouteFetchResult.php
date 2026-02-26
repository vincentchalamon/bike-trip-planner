<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use App\ApiResource\Model\Coordinate;
use App\Enum\SourceType;

final readonly class RouteFetchResult
{
    /**
     * @param list<list<Coordinate>> $tracks 1 track for Tour/MyMaps, N tracks for Collection (1 per tour = 1 stage)
     */
    public function __construct(
        public SourceType $sourceType,
        public array $tracks,
        public ?string $title = null,
    ) {
    }
}
