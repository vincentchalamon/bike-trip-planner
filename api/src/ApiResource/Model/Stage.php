<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

final class Stage
{
    public ?WeatherForecast $weather = null;

    public ?string $gpxContent = null;

    /** @var Alert[] */
    public array $alerts = [];

    /** @var PointOfInterest[] */
    public array $pois = [];

    /** @var Accommodation[] */
    public array $accommodations = [];

    /**
     * @param list<Coordinate> $geometry
     */
    public function __construct(
        public int $dayNumber,
        public float $distance,
        public float $elevation,
        public Coordinate $startPoint,
        public Coordinate $endPoint,
        public array $geometry = [],
        public ?string $label = null,
    ) {
    }

    public function addAlert(Alert $alert): void
    {
        $this->alerts[] = $alert;
    }

    public function addPoi(PointOfInterest $poi): void
    {
        $this->pois[] = $poi;
    }

    public function addAccommodation(Accommodation $accommodation): void
    {
        $this->accommodations[] = $accommodation;
    }
}
