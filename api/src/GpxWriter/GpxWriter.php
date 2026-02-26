<?php

declare(strict_types=1);

namespace App\GpxWriter;

use App\ApiResource\Model\Coordinate;

final class GpxWriter implements GpxWriterInterface
{
    /**
     * Generates a valid GPX 1.1 XML string from a list of coordinates.
     *
     * @param list<Coordinate> $points
     */
    public function generate(array $points, string $trackName = ''): string
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('    ');

        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('gpx');
        $xml->writeAttribute('version', '1.1');
        $xml->writeAttribute('creator', 'BikeTripPlanner');
        $xml->writeAttribute('xmlns', 'http://www.topografix.com/GPX/1/1');

        $xml->startElement('trk');
        $xml->writeElement('name', $trackName);

        $xml->startElement('trkseg');

        foreach ($points as $point) {
            $xml->startElement('trkpt');
            $xml->writeAttribute('lat', (string) $point->lat);
            $xml->writeAttribute('lon', (string) $point->lon);
            $xml->writeElement('ele', (string) $point->ele);
            $xml->endElement(); // trkpt
        }

        $xml->endElement(); // trkseg
        $xml->endElement(); // trk
        $xml->endElement(); // gpx

        return $xml->outputMemory();
    }
}
