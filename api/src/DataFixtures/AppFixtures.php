<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Factory\StageFactory;
use App\Factory\TripFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Trip 1: Tour de France bikepacking
        $trip1 = TripFactory::createOne([
            'title' => 'Tour de France Bikepacking',
            'sourceUrl' => 'https://www.komoot.com/tour/123456789',
            'startDate' => new \DateTimeImmutable('2026-07-01'),
            'endDate' => new \DateTimeImmutable('2026-07-10'),
            'locale' => 'fr',
        ]);

        StageFactory::createOne([
            'trip' => $trip1,
            'position' => 0,
            'dayNumber' => 1,
            'distance' => 85.2,
            'elevation' => 920.0,
            'elevationLoss' => 780.0,
            'startLat' => 48.8566,
            'startLon' => 2.3522,
            'startEle' => 35.0,
            'endLat' => 47.9983,
            'endLon' => 3.5736,
            'endEle' => 180.0,
            'label' => 'Paris → Sens',
        ]);

        StageFactory::createOne([
            'trip' => $trip1,
            'position' => 1,
            'dayNumber' => 2,
            'distance' => 92.7,
            'elevation' => 1050.0,
            'elevationLoss' => 890.0,
            'startLat' => 47.9983,
            'startLon' => 3.5736,
            'startEle' => 180.0,
            'endLat' => 47.3220,
            'endLon' => 5.0415,
            'endEle' => 245.0,
            'label' => 'Sens → Dijon',
        ]);

        StageFactory::createOne([
            'trip' => $trip1,
            'position' => 2,
            'dayNumber' => 3,
            'distance' => 0.0,
            'elevation' => 0.0,
            'elevationLoss' => 0.0,
            'startLat' => 47.3220,
            'startLon' => 5.0415,
            'startEle' => 245.0,
            'endLat' => 47.3220,
            'endLon' => 5.0415,
            'endEle' => 245.0,
            'label' => 'Repos à Dijon',
            'isRestDay' => true,
        ]);

        // Trip 2: E-bike Alpine adventure
        $trip2 = TripFactory::createOne([
            'title' => 'Alpine E-Bike Adventure',
            'sourceUrl' => 'https://www.komoot.com/tour/987654321',
            'startDate' => new \DateTimeImmutable('2026-08-15'),
            'endDate' => new \DateTimeImmutable('2026-08-20'),
            'ebikeMode' => true,
            'maxDistancePerDay' => 60.0,
            'locale' => 'en',
        ]);

        StageFactory::createOne([
            'trip' => $trip2,
            'position' => 0,
            'dayNumber' => 1,
            'distance' => 55.0,
            'elevation' => 1800.0,
            'elevationLoss' => 900.0,
            'startLat' => 45.1885,
            'startLon' => 5.7245,
            'startEle' => 214.0,
            'endLat' => 45.0522,
            'endLon' => 6.3442,
            'endEle' => 1326.0,
            'label' => 'Grenoble → Alpe d\'Huez',
        ]);
    }
}
