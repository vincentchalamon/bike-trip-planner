<?php

declare(strict_types=1);

namespace App\Tests\Unit\Poi;

use App\Poi\PoiLabelResolver;
use App\Tests\Unit\AlertMessageTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PoiLabelResolverTest extends TestCase
{
    use AlertMessageTestTrait;

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'french resupply' => ['fr', 'supermarket', 'supermarché', 'Supermarché'];
        yield 'english resupply' => ['en', 'supermarket', 'supermarket', 'Supermarket'];
        yield 'french cultural' => ['fr', 'castle', 'château', 'Château'];
        yield 'french underscored' => ['fr', 'archaeological_site', 'site archéologique', 'Site archéologique'];
        yield 'french unmapped falls back' => ['fr', 'obelisk', 'site remarquable', 'Site remarquable'];
        yield 'english unmapped falls back' => ['en', 'obelisk', 'point of interest', 'Point of interest'];

        // In-ride vocabulary added with the opening-hours tri-state (#931):
        // water, shelter, bike, health and transport categories in both locales.
        yield 'fr drinking_water' => ['fr', 'drinking_water', 'eau potable', 'Eau potable'];
        yield 'en drinking_water' => ['en', 'drinking_water', 'drinking water', 'Drinking water'];
        yield 'fr water_point' => ['fr', 'water_point', "point d'eau", "Point d'eau"];
        yield 'en water_point' => ['en', 'water_point', 'water point', 'Water point'];
        yield 'fr water_tap' => ['fr', 'water_tap', 'robinet', 'Robinet'];
        yield 'en water_tap' => ['en', 'water_tap', 'water tap', 'Water tap'];
        yield 'fr fountain' => ['fr', 'fountain', 'fontaine', 'Fontaine'];
        yield 'en fountain' => ['en', 'fountain', 'fountain', 'Fountain'];
        yield 'fr spring' => ['fr', 'spring', 'source', 'Source'];
        yield 'en spring' => ['en', 'spring', 'spring', 'Spring'];
        yield 'fr shelter' => ['fr', 'shelter', 'abri', 'Abri'];
        yield 'en shelter' => ['en', 'shelter', 'shelter', 'Shelter'];
        yield 'fr shelter_bus' => ['fr', 'shelter_bus', 'abribus', 'Abribus'];
        yield 'en shelter_bus' => ['en', 'shelter_bus', 'bus shelter', 'Bus shelter'];
        yield 'fr bicycle' => ['fr', 'bicycle', 'vélociste', 'Vélociste'];
        yield 'en bicycle' => ['en', 'bicycle', 'bike shop', 'Bike shop'];
        yield 'fr repair_station' => ['fr', 'repair_station', 'station de réparation', 'Station de réparation'];
        yield 'en repair_station' => ['en', 'repair_station', 'repair station', 'Repair station'];
        yield 'fr hospital' => ['fr', 'hospital', 'hôpital', 'Hôpital'];
        yield 'en hospital' => ['en', 'hospital', 'hospital', 'Hospital'];
        yield 'fr clinic' => ['fr', 'clinic', 'clinique', 'Clinique'];
        yield 'en clinic' => ['en', 'clinic', 'clinic', 'Clinic'];
        yield 'fr station' => ['fr', 'station', 'gare', 'Gare'];
        yield 'en station' => ['en', 'station', 'train station', 'Train station'];
        yield 'fr charging_station' => ['fr', 'charging_station', 'borne de recharge', 'Borne de recharge'];
        yield 'en charging_station' => ['en', 'charging_station', 'charging station', 'Charging station'];
    }

    #[DataProvider('labelProvider')]
    #[Test]
    public function resolvesTheLocalisedLabelAndItsDisplayForm(string $locale, string $category, string $label, string $displayName): void
    {
        $resolver = new PoiLabelResolver($this->createAlertTranslator());

        self::assertSame($label, $resolver->label($category, $locale));
        self::assertSame($displayName, $resolver->displayName($category, $locale));
    }

    #[Test]
    public function neverReturnsTheRawCategorySlug(): void
    {
        $resolver = new PoiLabelResolver($this->createAlertTranslator());

        self::assertSame('boulangerie', $resolver->label('bakery', 'fr'));
        self::assertSame('restauration rapide', $resolver->label('fast_food', 'fr'));
    }
}
