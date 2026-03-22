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
        // Trip 1: Entre Sensée et Escaut
        $trip1 = TripFactory::createOne([ // @phpstan-ignore staticMethod.unresolvableReturnType
            'title' => 'Entre Sensée et Escaut',
            'sourceUrl' => 'https://www.komoot.com/fr-fr/tour/2795080048',
            'startDate' => new \DateTimeImmutable('2026-07-01'),
            'endDate' => new \DateTimeImmutable('2026-07-03'),
            'locale' => 'fr',
        ]);

        StageFactory::createOne([
            'trip' => $trip1,
            'position' => 0,
            'dayNumber' => 1,
            'distance' => 65.3,
            'elevation' => 120.0,
            'elevationLoss' => 115.0,
            'startLat' => 50.2910,
            'startLon' => 3.1740,
            'startEle' => 45.0,
            'endLat' => 50.3580,
            'endLon' => 3.3220,
            'endEle' => 40.0,
            'label' => 'Cambrai → Bouchain',
        ]);

        StageFactory::createOne([
            'trip' => $trip1,
            'position' => 1,
            'dayNumber' => 2,
            'distance' => 58.7,
            'elevation' => 95.0,
            'elevationLoss' => 100.0,
            'startLat' => 50.3580,
            'startLon' => 3.3220,
            'startEle' => 40.0,
            'endLat' => 50.3667,
            'endLon' => 3.5225,
            'endEle' => 35.0,
            'label' => 'Bouchain → Valenciennes',
        ]);

        // Trip 2: L'Odyssée des Eaux Royales
        $trip2 = TripFactory::createOne([ // @phpstan-ignore staticMethod.unresolvableReturnType
            'title' => "L'Odyssée des Eaux Royales",
            'sourceUrl' => 'https://www.komoot.com/fr-fr/tour/2796682420',
            'startDate' => new \DateTimeImmutable('2026-08-10'),
            'endDate' => new \DateTimeImmutable('2026-08-14'),
            'locale' => 'fr',
        ]);

        StageFactory::createOne([
            'trip' => $trip2,
            'position' => 0,
            'dayNumber' => 1,
            'distance' => 72.1,
            'elevation' => 280.0,
            'elevationLoss' => 260.0,
            'startLat' => 48.8048,
            'startLon' => 2.1203,
            'startEle' => 160.0,
            'endLat' => 48.6843,
            'endLon' => 1.8365,
            'endEle' => 130.0,
            'label' => 'Versailles → Rambouillet',
        ]);

        StageFactory::createOne([
            'trip' => $trip2,
            'position' => 1,
            'dayNumber' => 2,
            'distance' => 0.0,
            'elevation' => 0.0,
            'elevationLoss' => 0.0,
            'startLat' => 48.6843,
            'startLon' => 1.8365,
            'startEle' => 130.0,
            'endLat' => 48.6843,
            'endLon' => 1.8365,
            'endEle' => 130.0,
            'label' => 'Repos à Rambouillet',
            'isRestDay' => true,
        ]);

        // Trip 3: L'Échappée Sauvage (e-bike)
        $trip3 = TripFactory::createOne([ // @phpstan-ignore staticMethod.unresolvableReturnType
            'title' => "L'Échappée Sauvage",
            'sourceUrl' => 'https://www.komoot.com/fr-fr/tour/2796709729',
            'startDate' => new \DateTimeImmutable('2026-09-01'),
            'endDate' => new \DateTimeImmutable('2026-09-05'),
            'ebikeMode' => true,
            'maxDistancePerDay' => 60.0,
            'locale' => 'fr',
        ]);

        StageFactory::createOne([
            'trip' => $trip3,
            'position' => 0,
            'dayNumber' => 1,
            'distance' => 55.4,
            'elevation' => 650.0,
            'elevationLoss' => 580.0,
            'startLat' => 50.4280,
            'startLon' => 2.8310,
            'startEle' => 75.0,
            'endLat' => 50.5450,
            'endLon' => 2.6320,
            'endEle' => 45.0,
            'label' => 'Béthune → Aire-sur-la-Lys',
        ]);
    }
}
