<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\State\TripCreateProcessor;
use App\State\TripPatchProcessor;
use App\State\TripStateProvider;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'Trip',
    operations: [
        new Post(
            uriTemplate: '/trips',
            status: 202,
            validationContext: ['groups' => ['trip_request:create']],
            output: TripResponse::class,
            processor: TripCreateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/trips/{id}',
            status: 202,
            output: TripResponse::class,
            provider: TripStateProvider::class,
            processor: TripPatchProcessor::class,
        ),
    ],
)]
final class TripRequest
{
    #[Assert\NotBlank(groups: ['trip_request:create'])]
    #[Assert\Url(protocols: ['https'])]
    #[Assert\Regex(
        pattern: '^https:\/\/(?:www\.komoot\.com\/(?:[a-z]{2}-[a-z]{2}\/)?(?:tour\/\d+|collection\/\d+\/.+)|www\.google\.com\/maps\/d\/.+|maps\.app\.goo\.gl\/.+)',
        message: 'The URL must be a valid Komoot tour/collection, Google My Maps or maps.app.goo.gl link.',
    )] // https://rubular.com/r/C4ppwWSMqcISRc
    public ?string $sourceUrl = null;

    public ?\DateTimeImmutable $startDate = null;

    // Number of days: endDate - startDate + 1
    // If endDate omitted, default from distance (ceil(distance/80))
    #[Assert\GreaterThan(propertyPath: 'startDate', message: 'End date must be after start date.')]
    public ?\DateTimeImmutable $endDate = null;

    // Fatigue factor (0.9 = -10%/day), configurable by the user
    #[Assert\Range(min: 0.5, max: 1.0)]
    public float $fatigueFactor = 0.9;

    // Elevation penalty (50 = -1km par 50m D+), configurable by the user
    #[Assert\Positive]
    public float $elevationPenalty = 50.0;
}
