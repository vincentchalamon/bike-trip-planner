<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\Geo\HaversineDistance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HaversineDistanceTest extends TestCase
{
    private HaversineDistance $haversine;

    #[\Override]
    protected function setUp(): void
    {
        $this->haversine = new HaversineDistance();
    }

    #[Test]
    public function samePointReturnsZero(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->haversine->inMeters(45.0, 5.0, 45.0, 5.0), 0.001);
    }

    #[Test]
    public function knownDistanceParisToBrussels(): void
    {
        // Paris (48.8566, 2.3522) to Brussels (50.8503, 4.3517) ≈ 264 km
        $meters = $this->haversine->inMeters(48.8566, 2.3522, 50.8503, 4.3517);

        $this->assertEqualsWithDelta(264_000.0, $meters, 5000.0);
    }

    #[Test]
    public function inKilometersReturnsDividedByThousand(): void
    {
        $meters = $this->haversine->inMeters(48.8566, 2.3522, 50.8503, 4.3517);
        $km = $this->haversine->inKilometers(48.8566, 2.3522, 50.8503, 4.3517);

        $this->assertEqualsWithDelta($meters / 1000.0, $km, 0.001);
    }

    #[Test]
    public function shortDistanceAccuracy(): void
    {
        // Two points ~111 meters apart (0.001 degree latitude)
        $meters = $this->haversine->inMeters(45.0, 5.0, 45.001, 5.0);

        $this->assertEqualsWithDelta(111.0, $meters, 5.0);
    }

    #[Test]
    public function symmetry(): void
    {
        $forward = $this->haversine->inMeters(45.0, 5.0, 46.0, 6.0);
        $reverse = $this->haversine->inMeters(46.0, 6.0, 45.0, 5.0);

        $this->assertEqualsWithDelta($forward, $reverse, 0.001);
    }
}
