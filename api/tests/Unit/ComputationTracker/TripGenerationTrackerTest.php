<?php

declare(strict_types=1);

namespace App\Tests\Unit\ComputationTracker;

use Override;
use App\ComputationTracker\TripGenerationTracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TripGenerationTrackerTest extends TestCase
{
    private TripGenerationTracker $tracker;

    #[Override]
    protected function setUp(): void
    {
        $this->tracker = new TripGenerationTracker(new ArrayAdapter());
    }

    #[Test]
    public function initializeSetsGenerationToOne(): void
    {
        $this->tracker->initialize('trip-1');

        $this->assertSame(1, $this->tracker->current('trip-1'));
    }

    #[Test]
    public function incrementReturnsNextGeneration(): void
    {
        $this->tracker->initialize('trip-1');

        $this->assertSame(2, $this->tracker->increment('trip-1'));
        $this->assertSame(3, $this->tracker->increment('trip-1'));
    }

    #[Test]
    public function currentReturnsNullForUnknownTrip(): void
    {
        $this->assertNull($this->tracker->current('unknown'));
    }

    #[Test]
    public function incrementFromZeroWhenNotInitialized(): void
    {
        $this->assertSame(1, $this->tracker->increment('trip-new'));
        $this->assertSame(1, $this->tracker->current('trip-new'));
    }

    #[Test]
    public function independentTripsDoNotInterfere(): void
    {
        $this->tracker->initialize('trip-1');
        $this->tracker->initialize('trip-2');

        $this->tracker->increment('trip-1');
        $this->tracker->increment('trip-1');

        $this->assertSame(3, $this->tracker->current('trip-1'));
        $this->assertSame(1, $this->tracker->current('trip-2'));
    }
}
