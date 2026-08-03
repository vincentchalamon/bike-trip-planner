<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use App\ApiResource\TripRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TripRequestTest extends TestCase
{
    #[Test]
    public function rentalIsSearchableButNotEnabledOnANewTrip(): void
    {
        // `rental` (gîte / meublé) is part of the searchable vocabulary, so the
        // repositories' `category IN (:categories)` filter can return it, but it
        // is opt-in: a large share of that market is let by the week.
        self::assertContains('rental', TripRequest::ALL_ACCOMMODATION_TYPES);
        self::assertNotContains('rental', TripRequest::DEFAULT_ACCOMMODATION_TYPES);
        self::assertNotContains('rental', new TripRequest()->enabledAccommodationTypes);
    }

    #[Test]
    public function theDefaultTypesAreASubsetOfTheSearchableVocabulary(): void
    {
        self::assertSame([], array_diff(TripRequest::DEFAULT_ACCOMMODATION_TYPES, TripRequest::ALL_ACCOMMODATION_TYPES));
        self::assertSame(TripRequest::DEFAULT_ACCOMMODATION_TYPES, new TripRequest()->enabledAccommodationTypes);
    }
}
