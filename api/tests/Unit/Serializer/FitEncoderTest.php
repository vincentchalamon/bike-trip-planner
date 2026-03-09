<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\Geo\HaversineDistance;
use App\Serializer\FitEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FitEncoderTest extends TestCase
{
    #[Test]
    public function encodeProducesValidFitHeader(): void
    {
        $encoder = new FitEncoder(new HaversineDistance());
        $data = $encoder->encode($this->sampleData(), 'fit');

        // FIT header is 14 bytes
        self::assertGreaterThanOrEqual(14, \strlen($data));

        // Header size byte
        self::assertSame(14, \ord($data[0]));

        // Data type signature ".FIT"
        self::assertSame('.FIT', substr($data, 8, 4));
    }

    #[Test]
    public function encodeEndsWithCrc16(): void
    {
        $encoder = new FitEncoder(new HaversineDistance());
        $data = $encoder->encode($this->sampleData(), 'fit');

        // File must end with 2-byte CRC
        self::assertGreaterThanOrEqual(16, \strlen($data));

        // The CRC is the last 2 bytes — verify it's a valid uint16
        $crcBytes = substr($data, -2);
        $crc = unpack('v', $crcBytes);
        self::assertIsArray($crc);
        self::assertArrayHasKey(1, $crc);
        self::assertGreaterThanOrEqual(0, $crc[1]);
        self::assertLessThanOrEqual(0xFFFF, $crc[1]);
    }

    #[Test]
    public function encodeWithWaypointsProducesLargerOutput(): void
    {
        $encoder = new FitEncoder(new HaversineDistance());

        $dataWith = $encoder->encode($this->sampleDataWithWaypoints(), 'fit');
        $dataWithout = $encoder->encode($this->sampleData(), 'fit');

        self::assertGreaterThan(\strlen($dataWithout), \strlen($dataWith));
    }

    #[Test]
    public function encodeDataSizeMatchesHeader(): void
    {
        $encoder = new FitEncoder(new HaversineDistance());
        $data = $encoder->encode($this->sampleData(), 'fit');

        // Data size in header (bytes 4-7, little-endian uint32)
        $headerDataSize = unpack('V', substr($data, 4, 4));
        self::assertIsArray($headerDataSize);

        // Total file = 14 (header) + data size + 2 (file CRC)
        $expectedTotal = 14 + $headerDataSize[1] + 2;
        self::assertSame($expectedTotal, \strlen($data));
    }

    #[Test]
    public function encodeWithMultiplePointsProducesValidOutput(): void
    {
        $encoder = new FitEncoder(new HaversineDistance());
        $data = [
            'courseName' => 'Multi-point',
            'points' => [
                ['lat' => 50.629, 'lon' => 3.057, 'ele' => 42.0],
                ['lat' => 50.650, 'lon' => 3.070, 'ele' => 55.0],
                ['lat' => 50.700, 'lon' => 3.100, 'ele' => 60.0],
            ],
            'waypoints' => [],
        ];

        $output = $encoder->encode($data, 'fit');

        self::assertSame('.FIT', substr($output, 8, 4));
        self::assertGreaterThan(50, \strlen($output));
    }

    #[Test]
    public function encodeWithNonArrayThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $encoder = new FitEncoder(new HaversineDistance());
        $encoder->encode('not an array', 'fit'); // @phpstan-ignore argument.type
    }

    #[Test]
    public function supportsFitFormatOnly(): void
    {
        $encoder = new FitEncoder(new HaversineDistance());

        self::assertTrue($encoder->supportsEncoding('fit'));
        self::assertFalse($encoder->supportsEncoding('gpx'));
        self::assertFalse($encoder->supportsEncoding('json'));
    }

    /**
     * @return array{courseName: string, points: list<array{lat: float, lon: float, ele: float}>, waypoints: list<array{lat: float, lon: float, name: string, type: string}>}
     */
    private function sampleData(): array
    {
        return [
            'courseName' => 'Test',
            'points' => [
                ['lat' => 50.629, 'lon' => 3.057, 'ele' => 42.0],
            ],
            'waypoints' => [],
        ];
    }

    /**
     * @return array{courseName: string, points: list<array{lat: float, lon: float, ele: float}>, waypoints: list<array{lat: float, lon: float, name: string, type: string}>}
     */
    private function sampleDataWithWaypoints(): array
    {
        return [
            'courseName' => 'Stage 1',
            'points' => [
                ['lat' => 50.629, 'lon' => 3.057, 'ele' => 42.0],
                ['lat' => 50.700, 'lon' => 3.100, 'ele' => 50.0],
            ],
            'waypoints' => [
                ['lat' => 50.629, 'lon' => 3.057, 'name' => 'Bakery', 'type' => 'bakery'],
            ],
        ];
    }
}
