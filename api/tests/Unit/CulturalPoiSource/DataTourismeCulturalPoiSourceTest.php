<?php

declare(strict_types=1);

namespace App\Tests\Unit\CulturalPoiSource;

use App\CulturalPoiSource\DataTourismeCulturalPoiSource;
use App\Tourism\CulturalPoiRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataTourismeCulturalPoiSourceTest extends TestCase
{
    #[Test]
    public function mapsRepositoryRowsToCulturalPois(): void
    {
        $repository = $this->createStub(CulturalPoiRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturn([
            ['name' => 'Musée X', 'category' => 'museum', 'lat' => 48.5, 'lon' => 2.3, 'openingHours' => 'Mo-Fr 09:00-17:00', 'description' => 'Un musée.', 'wikidata' => 'Q42', 'website' => 'https://musee.test', 'imageUrl' => 'https://img.test/m.jpg', 'wikipediaUrl' => 'https://fr.wikipedia.org/wiki/Musee'],
            ['name' => null, 'category' => 'monument', 'lat' => 49.0, 'lon' => 3.0, 'openingHours' => null, 'description' => null, 'wikidata' => null, 'website' => null, 'imageUrl' => null, 'wikipediaUrl' => null],
        ]);

        $pois = new DataTourismeCulturalPoiSource($repository)->fetchForStages([[['lat' => 48.5, 'lon' => 2.3]]], 5000);

        self::assertCount(2, $pois);
        self::assertSame('Musée X', $pois[0]['name']);
        self::assertSame('museum', $pois[0]['type']);
        self::assertSame('Mo-Fr 09:00-17:00', $pois[0]['openingHours']);
        self::assertSame('Un musée.', $pois[0]['description']);
        self::assertSame('Q42', $pois[0]['wikidataId']);
        self::assertSame('datatourisme', $pois[0]['source']);
        self::assertSame('https://img.test/m.jpg', $pois[0]['imageUrl']);
        self::assertSame('https://fr.wikipedia.org/wiki/Musee', $pois[0]['wikipediaUrl']);
        self::assertNull($pois[0]['estimatedPrice']);
        // A null name falls back to the category.
        self::assertSame('monument', $pois[1]['name']);
    }

    #[Test]
    public function isAlwaysEnabledAndNamedDatatourisme(): void
    {
        $source = new DataTourismeCulturalPoiSource($this->createStub(CulturalPoiRepositoryInterface::class));

        self::assertTrue($source->isEnabled());
        self::assertSame('datatourisme', $source->getName());
    }
}
