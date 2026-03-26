<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\Serializer\GpxEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GpxEncoderTest extends TestCase
{
    #[Test]
    public function encodeProducesValidGpxXml(): void
    {
        $encoder = new GpxEncoder();
        $xml = $encoder->encode($this->sampleData(), 'gpx');

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
    }

    #[Test]
    public function encodeContainsXsiNamespaceAttributes(): void
    {
        $encoder = new GpxEncoder();
        $xml = $encoder->encode($this->sampleData(), 'gpx');

        self::assertStringContainsString('xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"', $xml);
        self::assertStringContainsString('xsi:schemaLocation="http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd"', $xml);
    }

    #[Test]
    public function encodeContainsMetadataWithName(): void
    {
        $encoder = new GpxEncoder();
        $xml = $encoder->encode($this->sampleData(), 'gpx');

        self::assertStringContainsString('<metadata>', $xml);
        self::assertStringContainsString('<name>Stage 1</name>', $xml);
    }

    #[Test]
    public function encodeContainsMetadataLinkWhenSourceUrlProvided(): void
    {
        $encoder = new GpxEncoder();
        $data = $this->sampleData();
        $data['sourceUrl'] = 'https://www.komoot.com/tour/12345';

        $xml = $encoder->encode($data, 'gpx');

        self::assertStringContainsString('<link href="https://www.komoot.com/tour/12345"', $xml);
    }

    #[Test]
    public function encodeOmitsMetadataLinkWhenSourceUrlAbsent(): void
    {
        $encoder = new GpxEncoder();
        $xml = $encoder->encode($this->sampleData(), 'gpx');

        self::assertStringNotContainsString('<link', $xml);
    }

    #[Test]
    public function encodeContainsMetadataBeforeWaypointsBeforeTrack(): void
    {
        $encoder = new GpxEncoder();
        $xml = $encoder->encode($this->sampleData(), 'gpx');

        $metaPos = strpos($xml, '<metadata>');
        $wptPos = strpos($xml, '<wpt');
        $trkPos = strpos($xml, '<trk>');
        self::assertNotFalse($metaPos);
        self::assertNotFalse($wptPos);
        self::assertNotFalse($trkPos);
        self::assertLessThan($wptPos, $metaPos);
        self::assertLessThan($trkPos, $wptPos);
    }

    #[Test]
    public function encodeContainsCorrectAttributes(): void
    {
        $encoder = new GpxEncoder();
        $xml = $encoder->encode($this->sampleData(), 'gpx');

        // Waypoint
        self::assertStringContainsString('lat="50.629"', $xml);
        self::assertStringContainsString('lon="3.057"', $xml);
        self::assertStringContainsString('<name>Boulangerie</name>', $xml);
        self::assertStringContainsString('<sym>Shopping Center</sym>', $xml);
        self::assertStringContainsString('<type>bakery</type>', $xml);

        // Track
        self::assertStringContainsString('<name>Stage 1</name>', $xml);
        self::assertStringContainsString('<ele>42</ele>', $xml);
    }

    #[Test]
    public function encodeWithoutWaypointsProducesNoWptElements(): void
    {
        $encoder = new GpxEncoder();
        $data = [
            'trackName' => 'Stage 1',
            'points' => [['lat' => 50.629, 'lon' => 3.057, 'ele' => 42.0]],
            'waypoints' => [],
        ];

        $xml = $encoder->encode($data, 'gpx');

        self::assertStringNotContainsString('<wpt', $xml);
        self::assertStringContainsString('<trk>', $xml);
    }

    #[Test]
    public function encodeWithNonArrayThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $encoder = new GpxEncoder();
        $encoder->encode('not an array', 'gpx'); // @phpstan-ignore argument.type
    }

    #[Test]
    public function encodeWithSegmentsProducesMultipleTrksegs(): void
    {
        $encoder = new GpxEncoder();
        $data = [
            'trackName' => 'Full Trip',
            'segments' => [
                [
                    ['lat' => 50.629, 'lon' => 3.057, 'ele' => 42.0],
                    ['lat' => 50.700, 'lon' => 3.100, 'ele' => 50.0],
                ],
                [
                    ['lat' => 50.700, 'lon' => 3.100, 'ele' => 50.0],
                    ['lat' => 50.800, 'lon' => 3.200, 'ele' => 60.0],
                ],
            ],
            'waypoints' => [],
        ];

        $xml = $encoder->encode($data, 'gpx');

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
        self::assertSame(2, $doc->getElementsByTagName('trkseg')->length);
        self::assertSame(1, $doc->getElementsByTagName('trk')->length);
    }

    #[Test]
    public function supportsGpxFormatOnly(): void
    {
        $encoder = new GpxEncoder();

        self::assertTrue($encoder->supportsEncoding('gpx'));
        self::assertFalse($encoder->supportsEncoding('json'));
        self::assertFalse($encoder->supportsEncoding('fit'));
    }

    /**
     * @return array{trackName: string, points: list<array{lat: float, lon: float, ele: float}>, waypoints: list<array{lat: float, lon: float, name: string, symbol: string, type: string}>}
     */
    private function sampleData(): array
    {
        return [
            'trackName' => 'Stage 1',
            'points' => [
                ['lat' => 50.629, 'lon' => 3.057, 'ele' => 42.0],
                ['lat' => 50.700, 'lon' => 3.100, 'ele' => 50.0],
            ],
            'waypoints' => [
                ['lat' => 50.629, 'lon' => 3.057, 'name' => 'Boulangerie', 'symbol' => 'Shopping Center', 'type' => 'bakery'],
                ['lat' => 50.638, 'lon' => 3.061, 'name' => 'Camping', 'symbol' => 'Campground', 'type' => 'camp_site'],
            ],
        ];
    }
}
