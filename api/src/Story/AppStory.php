<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\StageFactory;
use App\Factory\TripFactory;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

#[AsFixture(name: 'app')]
final class AppStory extends Story
{
    public function build(): void
    {
        // Trip 1: Entre Sensée et Escaut
        $trip1 = TripFactory::createOne([ // @phpstan-ignore staticMethod.unresolvableReturnType
            'title' => 'Entre Sensée et Escaut',
            'sourceUrl' => 'https://www.komoot.com/fr-fr/tour/2795080048',
            'startDate' => new \DateTimeImmutable('2026-05-01'),
            'endDate' => new \DateTimeImmutable('2026-05-03'),
            'maxDistancePerDay' => 80.0,
            'averageSpeed' => 15.0,
            'locale' => 'fr',
            'sourceType' => 'komoot',
        ]);

        // Stage 1: ~65km, arrêt camping "La Paille Haute"
        StageFactory::createOne([
            'trip' => $trip1,
            'position' => 0,
            'dayNumber' => 1,
            'distance' => 65.3,
            'elevation' => 120.0,
            'elevationLoss' => 115.0,
            'startLat' => 50.3762,
            'startLon' => 3.0196,
            'startEle' => 50.0,
            'endLat' => 50.3055,
            'endLon' => 3.2578,
            'endEle' => 42.0,
            'label' => 'Lille → Camping La Paille Haute',
            'geometry' => [
                ['lat' => 50.3762, 'lon' => 3.0196, 'ele' => 50.0],
                ['lat' => 50.3500, 'lon' => 3.1000, 'ele' => 48.0],
                ['lat' => 50.3055, 'lon' => 3.2578, 'ele' => 42.0],
            ],
            'selectedAccommodation' => [
                'name' => 'Camping La Paille Haute',
                'type' => 'camp_site',
                'lat' => 50.3055,
                'lon' => 3.2578,
                'estimatedPriceMin' => 15.0,
                'estimatedPriceMax' => 25.0,
                'isExactPrice' => false,
                'possibleClosed' => false,
                'distanceToEndPoint' => 0.0,
            ],
        ]);

        // Stage 2: ~60km, arrêt camping "Le Mont des Bruyères" (near Saint-Amand-les-Eaux)
        StageFactory::createOne([
            'trip' => $trip1,
            'position' => 1,
            'dayNumber' => 2,
            'distance' => 60.1,
            'elevation' => 95.0,
            'elevationLoss' => 100.0,
            'startLat' => 50.3055,
            'startLon' => 3.2578,
            'startEle' => 42.0,
            'endLat' => 50.4483,
            'endLon' => 3.4316,
            'endEle' => 20.0,
            'label' => 'Camping La Paille Haute → Le Mont des Bruyères',
            'geometry' => [
                ['lat' => 50.3055, 'lon' => 3.2578, 'ele' => 42.0],
                ['lat' => 50.3700, 'lon' => 3.3500, 'ele' => 30.0],
                ['lat' => 50.4483, 'lon' => 3.4316, 'ele' => 20.0],
            ],
            'selectedAccommodation' => [
                'name' => 'Camping Le Mont des Bruyères',
                'type' => 'camp_site',
                'lat' => 50.4483,
                'lon' => 3.4316,
                'estimatedPriceMin' => 12.0,
                'estimatedPriceMax' => 20.0,
                'isExactPrice' => false,
                'possibleClosed' => false,
                'distanceToEndPoint' => 0.0,
            ],
        ]);

        // Stage 3: from camping to Lille
        StageFactory::createOne([
            'trip' => $trip1,
            'position' => 2,
            'dayNumber' => 3,
            'distance' => 55.8,
            'elevation' => 80.0,
            'elevationLoss' => 100.0,
            'startLat' => 50.4483,
            'startLon' => 3.4316,
            'startEle' => 20.0,
            'endLat' => 50.6292,
            'endLon' => 3.0573,
            'endEle' => 22.0,
            'label' => 'Le Mont des Bruyères → Lille',
            'geometry' => [
                ['lat' => 50.4483, 'lon' => 3.4316, 'ele' => 20.0],
                ['lat' => 50.5400, 'lon' => 3.2500, 'ele' => 25.0],
                ['lat' => 50.6292, 'lon' => 3.0573, 'ele' => 22.0],
            ],
        ]);

        // Trip 2: L'Odyssée des Eaux Royales
        $trip2 = TripFactory::createOne([ // @phpstan-ignore staticMethod.unresolvableReturnType
            'title' => "L'Odyssée des Eaux Royales",
            'sourceUrl' => 'https://www.komoot.com/fr-fr/tour/2796682420',
            'startDate' => new \DateTimeImmutable('2026-05-14'),
            'endDate' => new \DateTimeImmutable('2026-05-17'),
            'maxDistancePerDay' => 45.0,
            'averageSpeed' => 10.0,
            'locale' => 'fr',
            'sourceType' => 'komoot',
        ]);

        // Stage 1: Lille → Courtrai
        StageFactory::createOne([
            'trip' => $trip2,
            'position' => 0,
            'dayNumber' => 1,
            'distance' => 42.5,
            'elevation' => 65.0,
            'elevationLoss' => 60.0,
            'startLat' => 50.6292,
            'startLon' => 3.0573,
            'startEle' => 22.0,
            'endLat' => 50.8279,
            'endLon' => 3.2649,
            'endEle' => 15.0,
            'label' => 'Lille → Courtrai',
            'geometry' => [
                ['lat' => 50.6292, 'lon' => 3.0573, 'ele' => 22.0],
                ['lat' => 50.7300, 'lon' => 3.1600, 'ele' => 18.0],
                ['lat' => 50.8279, 'lon' => 3.2649, 'ele' => 15.0],
            ],
        ]);

        // Stage 2: Courtrai → Renaix
        StageFactory::createOne([
            'trip' => $trip2,
            'position' => 1,
            'dayNumber' => 2,
            'distance' => 38.7,
            'elevation' => 180.0,
            'elevationLoss' => 175.0,
            'startLat' => 50.8279,
            'startLon' => 3.2649,
            'startEle' => 15.0,
            'endLat' => 50.7484,
            'endLon' => 3.6010,
            'endEle' => 25.0,
            'label' => 'Courtrai → Renaix',
            'geometry' => [
                ['lat' => 50.8279, 'lon' => 3.2649, 'ele' => 15.0],
                ['lat' => 50.7900, 'lon' => 3.4300, 'ele' => 45.0],
                ['lat' => 50.7484, 'lon' => 3.6010, 'ele' => 25.0],
            ],
        ]);

        // Stage 3: Renaix → Tournai
        StageFactory::createOne([
            'trip' => $trip2,
            'position' => 2,
            'dayNumber' => 3,
            'distance' => 40.2,
            'elevation' => 110.0,
            'elevationLoss' => 115.0,
            'startLat' => 50.7484,
            'startLon' => 3.6010,
            'startEle' => 25.0,
            'endLat' => 50.6050,
            'endLon' => 3.3887,
            'endEle' => 20.0,
            'label' => 'Renaix → Tournai',
            'geometry' => [
                ['lat' => 50.7484, 'lon' => 3.6010, 'ele' => 25.0],
                ['lat' => 50.6800, 'lon' => 3.5000, 'ele' => 30.0],
                ['lat' => 50.6050, 'lon' => 3.3887, 'ele' => 20.0],
            ],
        ]);

        // Stage 4: Tournai → Lille
        StageFactory::createOne([
            'trip' => $trip2,
            'position' => 3,
            'dayNumber' => 4,
            'distance' => 35.1,
            'elevation' => 55.0,
            'elevationLoss' => 53.0,
            'startLat' => 50.6050,
            'startLon' => 3.3887,
            'startEle' => 20.0,
            'endLat' => 50.6292,
            'endLon' => 3.0573,
            'endEle' => 22.0,
            'label' => 'Tournai → Lille',
            'geometry' => [
                ['lat' => 50.6050, 'lon' => 3.3887, 'ele' => 20.0],
                ['lat' => 50.6200, 'lon' => 3.2200, 'ele' => 18.0],
                ['lat' => 50.6292, 'lon' => 3.0573, 'ele' => 22.0],
            ],
        ]);

        // Trip 3: L'Échappée Sauvage (no dates)
        $trip3 = TripFactory::createOne([ // @phpstan-ignore staticMethod.unresolvableReturnType
            'title' => "L'Échappée Sauvage",
            'sourceUrl' => 'https://www.komoot.com/fr-fr/tour/2796709729',
            'startDate' => null,
            'endDate' => null,
            'maxDistancePerDay' => 45.0,
            'averageSpeed' => 10.0,
            'locale' => 'fr',
            'sourceType' => 'komoot',
        ]);

        // Stage 1
        StageFactory::createOne([
            'trip' => $trip3,
            'position' => 0,
            'dayNumber' => 1,
            'distance' => 43.2,
            'elevation' => 220.0,
            'elevationLoss' => 210.0,
            'startLat' => 50.6292,
            'startLon' => 3.0573,
            'startEle' => 22.0,
            'endLat' => 50.4280,
            'endLon' => 2.8310,
            'endEle' => 75.0,
            'label' => 'Lille → Béthune',
            'geometry' => [
                ['lat' => 50.6292, 'lon' => 3.0573, 'ele' => 22.0],
                ['lat' => 50.5300, 'lon' => 2.9500, 'ele' => 45.0],
                ['lat' => 50.4280, 'lon' => 2.8310, 'ele' => 75.0],
            ],
        ]);

        // Stage 2
        StageFactory::createOne([
            'trip' => $trip3,
            'position' => 1,
            'dayNumber' => 2,
            'distance' => 41.8,
            'elevation' => 195.0,
            'elevationLoss' => 248.0,
            'startLat' => 50.4280,
            'startLon' => 2.8310,
            'startEle' => 75.0,
            'endLat' => 50.6292,
            'endLon' => 3.0573,
            'endEle' => 22.0,
            'label' => 'Béthune → Lille',
            'geometry' => [
                ['lat' => 50.4280, 'lon' => 2.8310, 'ele' => 75.0],
                ['lat' => 50.5300, 'lon' => 2.9500, 'ele' => 45.0],
                ['lat' => 50.6292, 'lon' => 3.0573, 'ele' => 22.0],
            ],
        ]);

        // Trip 4: La Boucle du Houblon & du Souvenir (no dates)
        $trip4 = TripFactory::createOne([ // @phpstan-ignore staticMethod.unresolvableReturnType
            'title' => 'La Boucle du Houblon & du Souvenir',
            'sourceUrl' => 'https://www.komoot.com/fr-fr/tour/2796700993',
            'startDate' => null,
            'endDate' => null,
            'maxDistancePerDay' => 45.0,
            'averageSpeed' => 10.0,
            'locale' => 'fr',
            'sourceType' => 'komoot',
        ]);

        // Stage 1: Lille → Bailleul
        StageFactory::createOne([
            'trip' => $trip4,
            'position' => 0,
            'dayNumber' => 1,
            'distance' => 44.6,
            'elevation' => 150.0,
            'elevationLoss' => 140.0,
            'startLat' => 50.6292,
            'startLon' => 3.0573,
            'startEle' => 22.0,
            'endLat' => 50.7393,
            'endLon' => 2.7339,
            'endEle' => 35.0,
            'label' => 'Lille → Bailleul',
            'geometry' => [
                ['lat' => 50.6292, 'lon' => 3.0573, 'ele' => 22.0],
                ['lat' => 50.6800, 'lon' => 2.9000, 'ele' => 28.0],
                ['lat' => 50.7393, 'lon' => 2.7339, 'ele' => 35.0],
            ],
        ]);

        // Stage 2: Bailleul → Poperinge
        StageFactory::createOne([
            'trip' => $trip4,
            'position' => 1,
            'dayNumber' => 2,
            'distance' => 38.9,
            'elevation' => 130.0,
            'elevationLoss' => 125.0,
            'startLat' => 50.7393,
            'startLon' => 2.7339,
            'startEle' => 35.0,
            'endLat' => 50.8539,
            'endLon' => 2.7244,
            'endEle' => 40.0,
            'label' => 'Bailleul → Poperinge',
            'geometry' => [
                ['lat' => 50.7393, 'lon' => 2.7339, 'ele' => 35.0],
                ['lat' => 50.7950, 'lon' => 2.7300, 'ele' => 50.0],
                ['lat' => 50.8539, 'lon' => 2.7244, 'ele' => 40.0],
            ],
        ]);

        // Stage 3: Poperinge → Lille
        StageFactory::createOne([
            'trip' => $trip4,
            'position' => 2,
            'dayNumber' => 3,
            'distance' => 42.3,
            'elevation' => 100.0,
            'elevationLoss' => 118.0,
            'startLat' => 50.8539,
            'startLon' => 2.7244,
            'startEle' => 40.0,
            'endLat' => 50.6292,
            'endLon' => 3.0573,
            'endEle' => 22.0,
            'label' => 'Poperinge → Lille',
            'geometry' => [
                ['lat' => 50.8539, 'lon' => 2.7244, 'ele' => 40.0],
                ['lat' => 50.7400, 'lon' => 2.8900, 'ele' => 30.0],
                ['lat' => 50.6292, 'lon' => 3.0573, 'ele' => 22.0],
            ],
        ]);

        // Trip 5: La Route de l'Eau, de la Lys à l'Aa (no dates)
        $trip5 = TripFactory::createOne([ // @phpstan-ignore staticMethod.unresolvableReturnType
            'title' => "La Route de l'Eau, de la Lys à l'Aa",
            'sourceUrl' => 'https://www.komoot.com/fr-fr/tour/2795061591',
            'startDate' => null,
            'endDate' => null,
            'maxDistancePerDay' => 45.0,
            'averageSpeed' => 10.0,
            'locale' => 'fr',
            'sourceType' => 'komoot',
        ]);

        // Stage 1: Lille → Aire-sur-la-Lys
        StageFactory::createOne([
            'trip' => $trip5,
            'position' => 0,
            'dayNumber' => 1,
            'distance' => 44.1,
            'elevation' => 85.0,
            'elevationLoss' => 78.0,
            'startLat' => 50.6292,
            'startLon' => 3.0573,
            'startEle' => 22.0,
            'endLat' => 50.6386,
            'endLon' => 2.3912,
            'endEle' => 18.0,
            'label' => 'Lille → Aire-sur-la-Lys',
            'geometry' => [
                ['lat' => 50.6292, 'lon' => 3.0573, 'ele' => 22.0],
                ['lat' => 50.6340, 'lon' => 2.7200, 'ele' => 20.0],
                ['lat' => 50.6386, 'lon' => 2.3912, 'ele' => 18.0],
            ],
        ]);

        // Stage 2: Aire-sur-la-Lys → Saint-Omer
        StageFactory::createOne([
            'trip' => $trip5,
            'position' => 1,
            'dayNumber' => 2,
            'distance' => 30.5,
            'elevation' => 60.0,
            'elevationLoss' => 58.0,
            'startLat' => 50.6386,
            'startLon' => 2.3912,
            'startEle' => 18.0,
            'endLat' => 50.7487,
            'endLon' => 2.2615,
            'endEle' => 5.0,
            'label' => 'Aire-sur-la-Lys → Saint-Omer',
            'geometry' => [
                ['lat' => 50.6386, 'lon' => 2.3912, 'ele' => 18.0],
                ['lat' => 50.6900, 'lon' => 2.3200, 'ele' => 12.0],
                ['lat' => 50.7487, 'lon' => 2.2615, 'ele' => 5.0],
            ],
        ]);

        // Stage 3: Saint-Omer → Lille
        StageFactory::createOne([
            'trip' => $trip5,
            'position' => 2,
            'dayNumber' => 3,
            'distance' => 43.7,
            'elevation' => 90.0,
            'elevationLoss' => 73.0,
            'startLat' => 50.7487,
            'startLon' => 2.2615,
            'startEle' => 5.0,
            'endLat' => 50.6292,
            'endLon' => 3.0573,
            'endEle' => 22.0,
            'label' => 'Saint-Omer → Lille',
            'geometry' => [
                ['lat' => 50.7487, 'lon' => 2.2615, 'ele' => 5.0],
                ['lat' => 50.6900, 'lon' => 2.6500, 'ele' => 15.0],
                ['lat' => 50.6292, 'lon' => 3.0573, 'ele' => 22.0],
            ],
        ]);
    }
}
