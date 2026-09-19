<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\NotExposed;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\Event;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;

#[NotExposed(
    uriTemplate: '/trips/{tripId}/stages/{stageId}{._format}',
    uriVariables: [
        'tripId' => new Link(toProperty: 'trip', fromClass: Trip::class, identifiers: ['id']),
        'stageId' => new Link(fromClass: StageResponse::class, identifiers: ['id']),
    ],
    shortName: 'Stage',
)]
final class StageResponse
{
    /**
     * The stage's stable identity, and what its IRI is built from (ADR-066).
     *
     * Previously the IRI was keyed on `dayNumber`, which every structural edit renumbers —
     * so the same IRI named a different stage after an insertion or a move.
     */
    public string $id;

    public ?WeatherForecast $weather = null;

    /** @var Alert[] */
    public array $alerts = [];

    public ?Resupply $resupply = null;

    /** @var Accommodation[] */
    public array $accommodations = [];

    public ?Accommodation $selectedAccommodation = null;

    /** @var Event[] */
    public array $events = [];

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

    public bool $isRestDay = false;
}
