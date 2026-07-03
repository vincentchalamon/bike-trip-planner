<?php

declare(strict_types=1);

namespace App\Tests\Unit\RouteParser;

use App\RouteParser\GpxStreamRouteParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the XXE hardening of the GPX parser (audit finding SEC-001): a malicious
 * external entity must never be substituted, and a DOCTYPE must be rejected.
 */
final class GpxStreamRouteParserTest extends TestCase
{
    private const string VALID_GPX = <<<'GPX'
        <?xml version="1.0" encoding="UTF-8"?>
        <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
        <trk><name>My Trip</name><trkseg>
        <trkpt lat="50.6292" lon="3.0573"><ele>20</ele></trkpt>
        <trkpt lat="50.6400" lon="3.0800"><ele>30</ele></trkpt>
        </trkseg></trk></gpx>
        GPX;

    #[Test]
    public function it_extracts_the_track_title_from_a_valid_gpx(): void
    {
        self::assertSame('My Trip', new GpxStreamRouteParser()->extractTitle(self::VALID_GPX));
    }

    #[Test]
    public function it_parses_track_points_from_a_valid_gpx(): void
    {
        $points = new GpxStreamRouteParser()->parse(self::VALID_GPX);

        self::assertCount(2, $points);
        self::assertEqualsWithDelta(50.6292, $points[0]->lat, 1e-6);
    }

    #[Test]
    public function it_does_not_substitute_an_external_entity_when_extracting_the_title(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'xxe');
        self::assertIsString($secret);
        file_put_contents($secret, 'TOP-SECRET-XXE-PROBE');

        try {
            $payload = \sprintf(
                "<?xml version=\"1.0\"?>\n<!DOCTYPE gpx [<!ENTITY xxe SYSTEM \"file://%s\">]>\n"
                ."<gpx version=\"1.1\" xmlns=\"http://www.topografix.com/GPX/1/1\">"
                ."<trk><name>&xxe;</name><trkseg>"
                ."<trkpt lat=\"50.6\" lon=\"3.0\"><ele>20</ele></trkpt>"
                ."</trkseg></trk></gpx>",
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
    public function it_rejects_a_gpx_carrying_a_doctype_when_parsing(): void
    {
        $this->expectException(\RuntimeException::class);

        new GpxStreamRouteParser()->parse(
            "<?xml version=\"1.0\"?>\n<!DOCTYPE gpx [<!ENTITY x \"y\">]>\n"
            .'<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
            .'<trk><trkseg><trkpt lat="50.6" lon="3.0"><ele>20</ele></trkpt></trkseg></trk></gpx>',
        );
    }
}
