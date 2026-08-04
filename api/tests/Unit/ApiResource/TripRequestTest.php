<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use App\ApiResource\TripRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TripRequestTest extends TestCase
{
    /**
     * `shelter`, `motel` and `rental` were removed from the vocabulary (#927).
     * A type left in the list here would be offered as lodging again, and one
     * left in a persisted trip would fail the `Assert\Choice` on the property —
     * hence Version20260805120000, which purges them.
     */
    #[Test]
    #[DataProvider('removedTypes')]
    public function theRemovedTypesAreNotSearchable(string $type): void
    {
        self::assertNotContains($type, TripRequest::ALL_ACCOMMODATION_TYPES);
        self::assertNotContains($type, new TripRequest()->enabledAccommodationTypes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function removedTypes(): iterable
    {
        yield 'shelter' => ['shelter'];
        yield 'motel' => ['motel'];
        yield 'rental' => ['rental'];
    }

    #[Test]
    public function aNewTripEnablesTheWholeVocabulary(): void
    {
        // No opt-in type left: `rental` was the only one, and it is gone (#927).
        self::assertSame(TripRequest::ALL_ACCOMMODATION_TYPES, new TripRequest()->enabledAccommodationTypes);
    }
}
