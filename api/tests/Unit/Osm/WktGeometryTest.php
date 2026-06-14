<?php

declare(strict_types=1);

namespace App\Tests\Unit\Osm;

use App\Osm\WktGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WktGeometryTest extends TestCase
{
    #[Test]
    public function lineStringForTwoOrMoreDistinctPoints(): void
    {
        self::assertSame(
            'LINESTRING(6.130000 49.600000,6.150000 49.620000)',
            WktGeometry::lineStringOrPoint([
                ['lat' => 49.60, 'lon' => 6.13],
                ['lat' => 49.62, 'lon' => 6.15],
            ]),
        );
    }

    #[Test]
    public function pointWhenAllVerticesCollapseToOne(): void
    {
        self::assertSame(
            'POINT(6.130000 49.600000)',
            WktGeometry::lineStringOrPoint([
                ['lat' => 49.60, 'lon' => 6.13],
                ['lat' => 49.60, 'lon' => 6.13],
            ]),
        );
    }

    #[Test]
    public function multiPointWrapsEachVertex(): void
    {
        self::assertSame(
            'MULTIPOINT((6.130000 49.600000),(6.150000 49.620000))',
            WktGeometry::multiPoint([
                ['lat' => 49.60, 'lon' => 6.13],
                ['lat' => 49.62, 'lon' => 6.15],
            ]),
        );
    }

    #[Test]
    public function lineStringOrPointRejectsEmptyInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WktGeometry::lineStringOrPoint([]);
    }

    #[Test]
    public function multiPointRejectsEmptyInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WktGeometry::multiPoint([]);
    }
}
