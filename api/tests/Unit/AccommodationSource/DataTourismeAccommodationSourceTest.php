<?php

declare(strict_types=1);

namespace App\Tests\Unit\AccommodationSource;

use App\AccommodationSource\DataTourismeAccommodationSource;
use App\ApiResource\Model\Coordinate;
use App\Engine\PricingHeuristicEngine;
use App\Tourism\AccommodationRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataTourismeAccommodationSourceTest extends TestCase
{
    #[Test]
    public function usesTheExactOfferPriceWhenPresent(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            ['name' => 'Gîte du Lac', 'category' => 'apartment', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => 4, 'price' => 75.0, 'description' => null],
        ]);
        $pricing = $this->createMock(PricingHeuristicEngine::class);
        $pricing->expects($this->never())->method('estimatePrice');

        $result = new DataTourismeAccommodationSource($repository, $pricing)->fetch([new Coordinate(48.0, 2.0)], 5000, ['apartment']);

        self::assertCount(1, $result);
        self::assertSame('Gîte du Lac', $result[0]['name']);
        self::assertSame(75.0, $result[0]['priceMin']);
        self::assertSame(75.0, $result[0]['priceMax']);
        self::assertTrue($result[0]['isExact']);
        self::assertSame('datatourisme', $result[0]['source']);
        self::assertNull($result[0]['wikidataId']);
    }

    #[Test]
    public function fallsBackToTheCategoryHeuristicWithoutAPrice(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            ['name' => null, 'category' => 'hotel', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => null, 'price' => null, 'description' => null],
        ]);
        $pricing = $this->createStub(PricingHeuristicEngine::class);
        $pricing->method('estimatePrice')->willReturn(['min' => 60.0, 'max' => 90.0, 'isExact' => false]);

        $result = new DataTourismeAccommodationSource($repository, $pricing)->fetch([new Coordinate(48.0, 2.0)], 5000, ['hotel']);

        self::assertSame('hotel', $result[0]['name'], 'a null name falls back to the category');
        self::assertSame(60.0, $result[0]['priceMin']);
        self::assertSame(90.0, $result[0]['priceMax']);
        self::assertFalse($result[0]['isExact']);
    }
}
