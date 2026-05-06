<?php

declare(strict_types=1);

namespace App\Tests\Unit\ComputationTracker;

use App\ComputationTracker\ComputationTracker;
use App\Enum\ComputationName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ComputationTrackerTest extends TestCase
{
    private ComputationTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        $this->tracker = new ComputationTracker(new ArrayAdapter());
    }

    #[Test]
    public function initializeComputationsSetsAllToPending(): void
    {
        $this->tracker->initializeComputations('trip-1', [
            ComputationName::ROUTE,
            ComputationName::STAGES,
            ComputationName::WEATHER,
        ]);

        $statuses = $this->tracker->getStatuses('trip-1');

        $this->assertNotNull($statuses);
        $this->assertSame('pending', $statuses['route']);
        $this->assertSame('pending', $statuses['stages']);
        $this->assertSame('pending', $statuses['weather']);
    }

    #[Test]
    public function markRunning(): void
    {
        $this->tracker->initializeComputations('trip-1', [ComputationName::ROUTE]);

        $this->tracker->markRunning('trip-1', ComputationName::ROUTE);

        $statuses = $this->tracker->getStatuses('trip-1');
        $this->assertNotNull($statuses);
        $this->assertSame('running', $statuses['route']);
    }

    #[Test]
    public function markDone(): void
    {
        $this->tracker->initializeComputations('trip-1', [ComputationName::ROUTE]);

        $this->tracker->markDone('trip-1', ComputationName::ROUTE);

        $statuses = $this->tracker->getStatuses('trip-1');
        $this->assertNotNull($statuses);
        $this->assertSame('done', $statuses['route']);
    }

    #[Test]
    public function markFailed(): void
    {
        $this->tracker->initializeComputations('trip-1', [ComputationName::ROUTE]);

        $this->tracker->markFailed('trip-1', ComputationName::ROUTE);

        $statuses = $this->tracker->getStatuses('trip-1');
        $this->assertNotNull($statuses);
        $this->assertSame('failed', $statuses['route']);
    }

    #[Test]
    public function resetComputation(): void
    {
        $this->tracker->initializeComputations('trip-1', [ComputationName::ROUTE]);
        $this->tracker->markDone('trip-1', ComputationName::ROUTE);

        $this->tracker->resetComputation('trip-1', ComputationName::ROUTE);

        $statuses = $this->tracker->getStatuses('trip-1');
        $this->assertNotNull($statuses);
        $this->assertSame('pending', $statuses['route']);
    }

    #[Test]
    public function getStatusesReturnsNullForUnknownTrip(): void
    {
        $this->assertNull($this->tracker->getStatuses('unknown-trip'));
    }

    #[Test]
    public function statusTransitionsWorkCorrectly(): void
    {
        $this->tracker->initializeComputations('trip-1', [ComputationName::ROUTE]);

        // pending → running → done
        $statuses = $this->tracker->getStatuses('trip-1');
        $this->assertNotNull($statuses);
        $this->assertSame('pending', $statuses['route']);

        $this->tracker->markRunning('trip-1', ComputationName::ROUTE);
        $statuses = $this->tracker->getStatuses('trip-1');
        $this->assertNotNull($statuses);
        $this->assertSame('running', $statuses['route']);

        $this->tracker->markDone('trip-1', ComputationName::ROUTE);
        $statuses = $this->tracker->getStatuses('trip-1');
        $this->assertNotNull($statuses);
        $this->assertSame('done', $statuses['route']);
    }

    #[Test]
    public function independentTripsDoNotInterfere(): void
    {
        $this->tracker->initializeComputations('trip-1', [ComputationName::ROUTE]);
        $this->tracker->initializeComputations('trip-2', [ComputationName::ROUTE]);

        $this->tracker->markDone('trip-1', ComputationName::ROUTE);

        $statuses1 = $this->tracker->getStatuses('trip-1');
        $this->assertNotNull($statuses1);
        $this->assertSame('done', $statuses1['route']);

        $statuses2 = $this->tracker->getStatuses('trip-2');
        $this->assertNotNull($statuses2);
        $this->assertSame('pending', $statuses2['route']);
    }

    #[Test]
    public function getStatusesBatchReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], $this->tracker->getStatusesBatch([]));
    }

    #[Test]
    public function getStatusesBatchReturnsNullForUntrackedTrips(): void
    {
        $result = $this->tracker->getStatusesBatch(['untracked-1', 'untracked-2']);

        $this->assertArrayHasKey('untracked-1', $result);
        $this->assertArrayHasKey('untracked-2', $result);
        $this->assertNull($result['untracked-1']);
        $this->assertNull($result['untracked-2']);
    }

    #[Test]
    public function getStatusesBatchReturnsMapsForTrackedTrips(): void
    {
        $this->tracker->initializeComputations('trip-1', [ComputationName::ROUTE]);
        $this->tracker->initializeComputations('trip-2', [ComputationName::STAGES]);
        $this->tracker->markDone('trip-1', ComputationName::ROUTE);
        $this->tracker->markRunning('trip-2', ComputationName::STAGES);

        $result = $this->tracker->getStatusesBatch(['trip-1', 'trip-2', 'untracked']);

        $this->assertNotNull($result['trip-1']);
        $this->assertSame('done', $result['trip-1']['route']);

        $this->assertNotNull($result['trip-2']);
        $this->assertSame('running', $result['trip-2']['stages']);

        $this->assertNull($result['untracked']);
    }
}
