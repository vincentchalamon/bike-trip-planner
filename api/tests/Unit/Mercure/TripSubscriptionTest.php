<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mercure;

use ApiPlatform\Metadata\Get;
use App\Mercure\TripSubscription;
use App\State\Mcp\TripDigestProvider;
use App\State\TripDetailProvider;
use App\State\TripShareShortCodeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(TripSubscription::class)]
final class TripSubscriptionTest extends TestCase
{
    private const string TRIP_UUID = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    #[Test]
    public function stampsWhenTheOwnerIsTheOperationsOwnProvider(): void
    {
        $request = new Request();

        TripSubscription::stamp(new Get(provider: TripDetailProvider::class), ['request' => $request], self::TRIP_UUID, TripDetailProvider::class);

        self::assertSame(self::TRIP_UUID, $request->attributes->get(TripSubscription::ATTRIBUTE));
    }

    #[Test]
    public function doesNotStampWhenReusedByAWrapper(): void
    {
        $share = new Request();
        $mcp = new Request();

        TripSubscription::stamp(new Get(provider: TripShareShortCodeProvider::class), ['request' => $share], self::TRIP_UUID, TripDetailProvider::class);
        TripSubscription::stamp(new Get(provider: TripDigestProvider::class), ['request' => $mcp], self::TRIP_UUID, TripDetailProvider::class);

        self::assertFalse($share->attributes->has(TripSubscription::ATTRIBUTE));
        self::assertFalse($mcp->attributes->has(TripSubscription::ATTRIBUTE));
    }

    #[Test]
    public function doesNotStampAnEmptyIdOrWithoutARequest(): void
    {
        $request = new Request();
        $operation = new Get(provider: TripDetailProvider::class);

        TripSubscription::stamp($operation, ['request' => $request], '', TripDetailProvider::class);
        TripSubscription::stamp($operation, [], self::TRIP_UUID, TripDetailProvider::class);

        self::assertFalse($request->attributes->has(TripSubscription::ATTRIBUTE));
    }
}
