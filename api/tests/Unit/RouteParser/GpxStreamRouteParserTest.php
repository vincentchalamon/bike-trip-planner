<?php

declare(strict_types=1);

namespace App\Tests\Unit\RouteParser;

use App\ApiResource\TripRequest;
use App\RouteParser\GpxStreamRouteParser;
use App\RouteParser\RouteTooLargeException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the XXE hardening of the GPX parser (audit finding SEC-001): a malicious
 * external entity must never be substituted, and a DOCTYPE must be rejected.
 */
final class GpxStreamRouteParserTest extends TestCase
{
    private const string VALID_GPX = <<<'GPX_WRAP'
    <?xml version="1.0" encoding="UTF-8"?>
    <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
    <trk><name>My Trip</name><trkseg>
    <trkpt lat="50.6292" lon="3.0573"><ele>20</ele></trkpt>
    <trkpt lat="50.6400" lon="3.0800"><ele>30</ele></trkpt>
    </trkseg></trk></gpx>
    GPX_WRAP;

    #[Test]
    public function itExtractsTheTrackTitleFromAValidGpx(): void
    {
        self::assertSame('My Trip', new GpxStreamRouteParser()->extractTitle(self::VALID_GPX));
    }

    #[Test]
    public function itParsesTrackPointsFromAValidGpx(): void
    {
        $points = new GpxStreamRouteParser()->parse(self::VALID_GPX);

        self::assertCount(2, $points);
        self::assertEqualsWithDelta(50.6292, $points[0]->lat, 1e-6);
    }

    #[Test]
    public function itDoesNotSubstituteAnExternalEntityWhenExtractingTheTitle(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'xxe');
        self::assertIsString($secret);
        file_put_contents($secret, 'TOP-SECRET-XXE-PROBE');

        try {
            $payload = \sprintf(
                "<?xml version=\"1.0\"?>\n<!DOCTYPE gpx [<!ENTITY xxe SYSTEM \"file://%s\">]>\n"
                .'<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
                .'<trk><name>&xxe;</name><trkseg>'
                .'<trkpt lat="50.6" lon="3.0"><ele>20</ele></trkpt>'
                .'</trkseg></trk></gpx>',
                $secret,
            );

            $title = new GpxStreamRouteParser()->extractTitle($payload);

            self::assertNotSame('TOP-SECRET-XXE-PROBE', $title, 'XXE: external entity was substituted — local file leaked.');
            self::assertNull($title);
        } finally {
            @unlink($secret);
        }
    }

    #[Test]
    public function itRejectsAGpxCarryingADoctypeWhenParsing(): void
    {
        $this->expectException(\RuntimeException::class);

        new GpxStreamRouteParser()->parse(
            "<?xml version=\"1.0\"?>\n<!DOCTYPE gpx [<!ENTITY x \"y\">]>\n"
            .'<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
            .'<trk><trkseg><trkpt lat="50.6" lon="3.0"><ele>20</ele></trkpt></trkseg></trk></gpx>',
        );
    }

    #[Test]
    public function itRejectsAUtf16GpxCarryingADoctypeWhenParsing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('DOCTYPE');

        new GpxStreamRouteParser()->parse($this->utf16(self::DOCTYPE_GPX));
    }

    #[Test]
    public function itIgnoresTheTitleOfAUtf16GpxCarryingADoctype(): void
    {
        self::assertNull(new GpxStreamRouteParser()->extractTitle($this->utf16(self::DOCTYPE_GPX)));
    }

    #[Test]
    public function itStillParsesAUtf16GpxWithoutDoctype(): void
    {
        $gpx = str_replace('encoding="UTF-8"', 'encoding="UTF-16"', self::VALID_GPX);

        self::assertCount(2, new GpxStreamRouteParser()->parse($this->utf16($gpx)));
        self::assertSame('My Trip', new GpxStreamRouteParser()->extractTitle($this->utf16($gpx)));
    }

    #[Test]
    public function itRejectsAGpxPastThePointCap(): void
    {
        $this->expectException(RouteTooLargeException::class);

        new GpxStreamRouteParser()->parse($this->gpxWithPoints(RouteTooLargeException::MAX_POINTS + 1));
    }

    #[Test]
    public function itAcceptsAGpxAtThePointCap(): void
    {
        self::assertCount(RouteTooLargeException::MAX_POINTS, new GpxStreamRouteParser()->parse($this->gpxWithPoints(RouteTooLargeException::MAX_POINTS)));
    }

    #[Test]
    public function itCutsAnOverlongTitleToTheColumnLength(): void
    {
        $gpx = str_replace('<name>My Trip</name>', '<name>'.str_repeat('é', 300).'</name>', self::VALID_GPX);

        $title = new GpxStreamRouteParser()->extractTitle($gpx);

        self::assertIsString($title);
        self::assertSame(TripRequest::TITLE_MAX_LENGTH, mb_strlen($title));
        self::assertTrue(mb_check_encoding($title, 'UTF-8'));
    }

    private const string DOCTYPE_GPX = "<?xml version=\"1.0\" encoding=\"UTF-16\"?>\n<!DOCTYPE gpx [<!ENTITY x \"y\">]>\n"
        .'<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
        .'<trk><name>Probe</name><trkseg><trkpt lat="50.6" lon="3.0"><ele>20</ele></trkpt></trkseg></trk></gpx>';

    /** UTF-16LE with a BOM: the byte-level DOCTYPE check cannot see through the null bytes. */
    private function utf16(string $xml): string
    {
        $encoded = mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');
        self::assertStringNotContainsString('<!DOCTYPE', $encoded);

        return "\xFF\xFE".$encoded;
    }

    private function gpxWithPoints(int $count): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>'
            .str_repeat('<trkpt lat="50.6" lon="3.0"/>', $count)
            .'</trkseg></trk></gpx>';
    }
}
