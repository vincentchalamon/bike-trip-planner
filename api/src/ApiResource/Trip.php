<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\TripCreateProcessor;
use App\State\TripGpxProvider;
use App\State\TripRequestProvider;
use App\State\TripUpdateProcessor;

#[ApiResource(
    shortName: 'Trip',
    operations: [
        new Post(
            uriTemplate: '/trips{._format}',
            status: 202,
            validationContext: ['groups' => ['trip_request:create']],
            input: TripRequest::class,
            mercure: true,
            processor: TripCreateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/trips/{id}{._format}',
            status: 202,
            input: TripRequest::class,
            mercure: true,
            provider: TripRequestProvider::class,
            processor: TripUpdateProcessor::class,
        ),
        new Get(
            uriTemplate: '/trips/{id}{._format}',
            outputFormats: [
                'gpx' => ['application/gpx+xml'],
            ],
            openapi: new Operation(summary: 'Download the full trip as a single GPX file containing all stages.'),
            provider: TripGpxProvider::class,
        ),
    ],
)]
final readonly class Trip
{
    /**
     * @param array<string, string> $computationStatus Map of ComputationName->value to status string
     */
    public function __construct(
        public string $id,
        public array $computationStatus = [],
    ) {
    }
}
