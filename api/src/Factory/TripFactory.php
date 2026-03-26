<?php

declare(strict_types=1);

namespace App\Factory;

use DateTimeImmutable;
use App\ApiResource\TripRequest;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<TripRequest>
 */
final class TripFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return TripRequest::class;
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return [
            'id' => Uuid::v7(),
            'sourceUrl' => 'https://www.komoot.com/tour/123456789',
            'title' => 'Test Trip',
            'startDate' => new DateTimeImmutable('+1 day'),
            'endDate' => new DateTimeImmutable('+5 days'),
            'fatigueFactor' => 0.9,
            'elevationPenalty' => 50.0,
            'ebikeMode' => false,
            'departureHour' => 8,
            'maxDistancePerDay' => 80.0,
            'averageSpeed' => 15.0,
            'enabledAccommodationTypes' => ['camp_site', 'hostel', 'alpine_hut', 'chalet', 'guest_house', 'motel', 'hotel'],
            'sourceType' => 'komoot',
            'locale' => 'fr',
        ];
    }
}
