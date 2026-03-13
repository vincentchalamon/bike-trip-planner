<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\NotExposed;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\WeatherForecast;

#[NotExposed(
    uriTemplate: '/trips/{tripId}/stages/{index}{._format}',
    uriVariables: [
        'tripId' => new Link(toProperty: 'trip', fromClass: Trip::class, identifiers: ['id']),
        'index' => new Link(fromClass: StageResponse::class, identifiers: ['dayNumber']),
    ],
    shortName: 'Stage',
)]
final class StageResponse
{
    public ?WeatherForecast $weather = null;

    /** @var Alert[] */
    public array $alerts = [];

    /** @var PointOfInterest[] */
    public array $pois = [];

    /** @var Accommodation[] */
    public array $accommodations = [];

    public ?Accommodation $selectedAccommodation = null;

    public Trip $trip;

    public int $dayNumber;

    public float $distance;

    public float $elevation;

    public float $elevationLoss;

    public Coordinate $startPoint;

    public Coordinate $endPoint;

    /**
     * @var list<Coordinate>
     */
    public array $geometry = [];

    public ?string $label = null;
}
