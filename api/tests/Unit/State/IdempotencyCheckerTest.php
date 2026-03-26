<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\ApiResource\TripRequest;
use App\State\IdempotencyChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class IdempotencyCheckerTest extends TestCase
{
    private ArrayAdapter $cache;

    private IdempotencyChecker $checker;

    #[\Override]
    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->checker = new IdempotencyChecker($this->cache);
    }

    #[Test]
    public function hasChangedReturnsTrueWhenNoHashStored(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123';

        $this->assertTrue($this->checker->hasChanged('trip-1', $request));
    }

    #[Test]
    public function hasChangedReturnsFalseAfterSavingIdenticalRequest(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123';
        $request->enabledAccommodationTypes = ['camp_site', 'hostel'];

        $this->checker->saveHash('trip-1', $request);

        $same = new TripRequest();
        $same->sourceUrl = 'https://www.komoot.com/tour/123';
        $same->enabledAccommodationTypes = ['camp_site', 'hostel'];

        $this->assertFalse($this->checker->hasChanged('trip-1', $same));
    }

    #[Test]
    public function hasChangedReturnsTrueWhenEnabledTypesAreDifferent(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123';
        $request->enabledAccommodationTypes = ['camp_site', 'hostel', 'alpine_hut'];

        $this->checker->saveHash('trip-1', $request);

        $changed = new TripRequest();
        $changed->sourceUrl = 'https://www.komoot.com/tour/123';
        $changed->enabledAccommodationTypes = ['camp_site', 'hostel'];

        $this->assertTrue($this->checker->hasChanged('trip-1', $changed));
    }

    #[Test]
    public function hasChangedReturnsFalseWhenEnabledTypesAreSameInDifferentOrder(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123';
        $request->enabledAccommodationTypes = ['hostel', 'camp_site'];

        $this->checker->saveHash('trip-1', $request);

        $reordered = new TripRequest();
        $reordered->sourceUrl = 'https://www.komoot.com/tour/123';
        $reordered->enabledAccommodationTypes = ['camp_site', 'hostel'];

        $this->assertFalse($this->checker->hasChanged('trip-1', $reordered));
    }
}
