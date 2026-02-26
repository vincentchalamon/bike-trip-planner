<?php

declare(strict_types=1);

namespace App\RouteParser;

use App\ApiResource\Model\Coordinate;

final class KmlRouteParser implements RouteParserInterface
{
    /**
     * Parses a KML string and returns all coordinates.
     * KML coordinates are in lon,lat,ele order.
     * Uses XMLReader for O(constant) memory usage.
     * Protected against XXE with LIBXML_NONET | LIBXML_NOENT.
     *
     * {@inheritdoc}
     */
    public function parse(string $content): array
    {
        $points = [];

        $reader = new \XMLReader();

        $options = \LIBXML_NONET | \LIBXML_NOENT;

        if (!$reader->XML($content, null, $options)) {
            throw new \RuntimeException('Failed to initialize XMLReader for KML content.');
        }

        $inCoordinates = false;

        libxml_use_internal_errors(true);

        while ($reader->read()) {
            if (\XMLReader::ELEMENT === $reader->nodeType && 'coordinates' === $reader->localName) {
                $inCoordinates = true;
            } elseif ($inCoordinates && \XMLReader::TEXT === $reader->nodeType) {
                $rawCoordinates = trim($reader->value);
                foreach (preg_split('/\s+/', $rawCoordinates) ?: [] as $triplet) {
                    $triplet = trim($triplet);
                    if ('' === $triplet) {
                        continue;
                    }

                    $parts = explode(',', $triplet);
                    if (\count($parts) < 2) {
                        continue;
                    }

                    // KML order: lon, lat, ele
                    $lon = (float) $parts[0];
                    $lat = (float) $parts[1];
                    $ele = isset($parts[2]) ? (float) $parts[2] : 0.0;

                    $points[] = new Coordinate($lat, $lon, $ele);
                }
            } elseif (\XMLReader::END_ELEMENT === $reader->nodeType && 'coordinates' === $reader->localName) {
                $inCoordinates = false;
            }
        }

        $reader->close();
        libxml_clear_errors();

        return $points;
    }
}
