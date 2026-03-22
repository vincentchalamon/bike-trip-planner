<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Stage;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Stage>
 */
final class StageFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Stage::class;
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return [
            'trip' => TripFactory::new(),
            'id' => Uuid::v7(),
            'position' => 0,
            'dayNumber' => 1,
            'distance' => 75.5,
            'elevation' => 850.0,
            'elevationLoss' => 720.0,
            'startLat' => 48.8566,
            'startLon' => 2.3522,
            'startEle' => 35.0,
            'endLat' => 47.3220,
            'endLon' => 5.0415,
            'endEle' => 245.0,
            'geometry' => [
                ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
                ['lat' => 48.0, 'lon' => 3.5, 'ele' => 150.0],
                ['lat' => 47.3220, 'lon' => 5.0415, 'ele' => 245.0],
            ],
            'label' => 'Paris → Dijon',
            'isRestDay' => false,
            'weather' => null,
            'alerts' => [],
            'pois' => [],
            'accommodations' => [],
            'selectedAccommodation' => null,
        ];
    }
}
