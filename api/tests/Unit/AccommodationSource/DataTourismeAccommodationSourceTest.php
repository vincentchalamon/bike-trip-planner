<?php

declare(strict_types=1);

namespace App\Tests\Unit\AccommodationSource;

use App\AccommodationSource\DataTourismeAccommodationSource;
use App\ApiResource\Model\Coordinate;
use App\DataTourisme\DataTourismeClientInterface;
use App\Engine\PricingHeuristicEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataTourismeAccommodationSourceTest extends TestCase
{
    #[Test]
    public function getNameReturnsDatatourisme(): void
    {
        $source = $this->createSource();

        $this->assertSame('datatourisme', $source->getName());
    }

    #[Test]
    public function isEnabledDelegatesToClient(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);

        $source = $this->createSource($client);

        $this->assertTrue($source->isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenClientDisabled(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(false);

        $source = $this->createSource($client);

        $this->assertFalse($source->isEnabled());
    }

    #[Test]
    public function fetchReturnsEmptyArrayForEmptyEndPoints(): void
    {
        $client = $this->createMock(DataTourismeClientInterface::class);
        $client->expects($this->never())->method('request');

        $source = $this->createSource($client);
        $results = $source->fetch([], 5000, ['hotel']);

        $this->assertSame([], $results);
    }

    #[Test]
    public function fetchMapsHotelItemCorrectly(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Hotel'],
                    'rdfs:label' => 'Hotel du Midi',
                    'hasGeometry' => ['latitude' => 48.6, 'longitude' => 2.6],
                    'foaf:homepage' => 'https://hotel-du-midi.fr',
                ],
            ],
        ]);

        $source = $this->createSource($client);
        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertCount(1, $results);
        $this->assertSame('Hotel du Midi', $results[0]['name']);
        $this->assertSame('hotel', $results[0]['type']);
        $this->assertSame(48.6, $results[0]['lat']);
        $this->assertSame(2.6, $results[0]['lon']);
        $this->assertSame('https://hotel-du-midi.fr', $results[0]['url']);
        $this->assertSame('datatourisme', $results[0]['source']);
    }

    #[Test]
    public function fetchMapsCampgroundToCampSiteType(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Campground'],
                    'rdfs:label' => 'Camping du Lac',
                    'hasGeometry' => ['latitude' => 47.0, 'longitude' => 3.0],
                ],
            ],
        ]);

        $source = $this->createSource($client);
        $results = $source->fetch([new Coordinate(47.0, 3.0)], 5000, ['camp_site']);

        $this->assertCount(1, $results);
        $this->assertSame('camp_site', $results[0]['type']);
    }

    #[Test]
    public function fetchExtractsPriceFromOffers(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Hotel'],
                    'rdfs:label' => 'Hotel Pricey',
                    'hasGeometry' => ['latitude' => 48.6, 'longitude' => 2.6],
                    'offers' => [
                        [
                            'priceSpecification' => [
                                ['minPrice' => 80, 'maxPrice' => 150],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $source = $this->createSource($client);
        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertCount(1, $results);
        $this->assertSame(80.0, $results[0]['priceMin']);
        $this->assertSame(150.0, $results[0]['priceMax']);
        $this->assertTrue($results[0]['isExact']);
    }

    #[Test]
    public function fetchUsesHeuristicPricingWhenNoOffers(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Hotel'],
                    'rdfs:label' => 'Hotel Simple',
                    'hasGeometry' => ['latitude' => 48.6, 'longitude' => 2.6],
                ],
            ],
        ]);

        $source = $this->createSource($client);
        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['isExact']);
        $this->assertSame(50.0, $results[0]['priceMin']);
        $this->assertSame(120.0, $results[0]['priceMax']);
    }

    #[Test]
    public function fetchExtractsWikidataIdFromOwlSameAs(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Hotel'],
                    'rdfs:label' => 'Hotel Wiki',
                    'hasGeometry' => ['latitude' => 48.6, 'longitude' => 2.6],
                    'owl:sameAs' => ['https://www.wikidata.org/wiki/Q99999'],
                ],
            ],
        ]);

        $source = $this->createSource($client);
        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertCount(1, $results);
        $this->assertSame('Q99999', $results[0]['wikidataId']);
    }

    #[Test]
    public function fetchSetsNullWikidataIdWhenNoSameAs(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Hostel'],
                    'rdfs:label' => 'Auberge de Jeunesse',
                    'hasGeometry' => ['latitude' => 45.0, 'longitude' => 4.0],
                ],
            ],
        ]);

        $source = $this->createSource($client);
        $results = $source->fetch([new Coordinate(45.0, 4.0)], 5000, ['hostel']);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]['wikidataId']);
    }

    #[Test]
    public function fetchSkipsItemsWithoutGeometry(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Hotel'],
                    'rdfs:label' => 'No Geo Hotel',
                ],
            ],
        ]);

        $source = $this->createSource($client);
        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertSame([], $results);
    }

    #[Test]
    public function fetchFiltersOutDisabledTypes(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Hotel'],
                    'rdfs:label' => 'Hotel Only',
                    'hasGeometry' => ['latitude' => 48.6, 'longitude' => 2.6],
                ],
                [
                    '@type' => ['schema:Hostel'],
                    'rdfs:label' => 'Hostel One',
                    'hasGeometry' => ['latitude' => 48.7, 'longitude' => 2.7],
                ],
            ],
        ]);

        $source = $this->createSource($client);
        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertCount(1, $results);
        $this->assertSame('hotel', $results[0]['type']);
    }

    private function createSource(?DataTourismeClientInterface $client = null): DataTourismeAccommodationSource
    {
        return new DataTourismeAccommodationSource(
            client: $client ?? $this->createStub(DataTourismeClientInterface::class),
            pricingEngine: new PricingHeuristicEngine(),
        );
    }
}
