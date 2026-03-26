<?php

declare(strict_types=1);

namespace App\Serializer;

use InvalidArgumentException;
use XMLWriter;
use Symfony\Component\Serializer\Encoder\EncoderInterface;

final readonly class GpxEncoder implements EncoderInterface
{
    /**
     * Encodes trip data to GPX format.
     *
     * Supports two shapes:
     * - Single-segment: `{trackName, points, waypoints}` — one `<trkseg>` per track.
     * - Multi-segment:  `{trackName, segments, waypoints}` — multiple `<trkseg>` (one per stage).
     *
     * @param array{trackName: string, sourceUrl?: string|null, points?: list<array{lat: float, lon: float, ele: float|null}>, segments?: list<list<array{lat: float, lon: float, ele: float|null}>>, waypoints: list<array{lat: float, lon: float, name: string, symbol: string, type: string}>} $data
     */
    public function encode(mixed $data, string $format, array $context = []): string
    {
        if (!\is_array($data)) {
            throw new InvalidArgumentException('GpxEncoder expects an array.');
        }

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('    ');

        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('gpx');
        $xml->writeAttribute('version', '1.1');
        $xml->writeAttribute('creator', 'BikeTripPlanner');
        $xml->writeAttribute('xmlns', 'http://www.topografix.com/GPX/1/1');
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeAttribute('xsi:schemaLocation', 'http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd');

        $xml->startElement('metadata');
        $xml->writeElement('name', $data['trackName']);
        if (!empty($data['sourceUrl'])) {
            $xml->startElement('link');
            $xml->writeAttribute('href', $data['sourceUrl']);
            $xml->endElement(); // link
        }

        $xml->endElement(); // metadata

        /** @var array{lat: float, lon: float, name: string, symbol: string, type: string} $waypoint */
        foreach ($data['waypoints'] as $waypoint) {
            $xml->startElement('wpt');
            $xml->writeAttribute('lat', (string) $waypoint['lat']);
            $xml->writeAttribute('lon', (string) $waypoint['lon']);
            $xml->writeElement('name', $waypoint['name']);
            $xml->writeElement('sym', $waypoint['symbol']);
            $xml->writeElement('type', $waypoint['type']);
            $xml->endElement(); // wpt
        }

        $xml->startElement('trk');
        $xml->writeElement('name', $data['trackName']);

        /** @var list<list<array{lat: float, lon: float, ele: float}>> $segments */
        $segments = $data['segments'] ?? [$data['points'] ?? []];

        foreach ($segments as $segmentPoints) {
            $xml->startElement('trkseg');

            /** @var array{lat: float, lon: float, ele: float} $point */
            foreach ($segmentPoints as $point) {
                $xml->startElement('trkpt');
                $xml->writeAttribute('lat', (string) $point['lat']);
                $xml->writeAttribute('lon', (string) $point['lon']);
                $xml->writeElement('ele', (string) $point['ele']);
                $xml->endElement(); // trkpt
            }

            $xml->endElement(); // trkseg
        }

        $xml->endElement(); // trk
        $xml->endElement(); // gpx

        return $xml->outputMemory();
    }

    public function supportsEncoding(string $format): bool
    {
        return 'gpx' === $format;
    }
}
