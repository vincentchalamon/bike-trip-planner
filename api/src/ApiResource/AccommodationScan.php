<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\AccommodationScanProcessor;

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
            input: AccommodationScanRequest::class,
            output: Trip::class,
            processor: AccommodationScanProcessor::class,
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
