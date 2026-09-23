<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\AccommodationScanProcessor;
use App\State\TripLockProcessor;

#[ApiResource(
    shortName: 'AccommodationScan',
    operations: [
        new Post(
            uriTemplate: '/trips/{tripId}/accommodations/scan{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: AccommodationScan::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Re-scan accommodations for all stages with a custom radius.'),
            security: "is_granted('TRIP_EDIT', tripId)",
            input: AccommodationScanRequest::class,
            output: Trip::class,
            processor: AccommodationScanProcessor::class,
            // The least guarded write in the API until now: no precondition, no rate limit, no
            // lock, and it rewrites every stage's accommodations including the selected one.
            extraProperties: [TripLockProcessor::EXTRA_PROPERTY => true],
        ),
    ],
)]
final readonly class AccommodationScan
{
    public function __construct(
        public string $tripId,
    ) {
    }
}
