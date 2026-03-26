<?php

declare(strict_types=1);

namespace App\RouteParser;

use XMLReader;
use App\ApiResource\Model\Coordinate;

final class GpxStreamRouteParser implements GpxRouteParserInterface
{
    /**
     * Extracts the first track name from a GPX string.
     * Uses XMLReader for O(constant) memory usage.
     */
    public function extractTitle(string $content): ?string
    {
        $previous = libxml_use_internal_errors(true);

        $reader = new \XMLReader();

        if (!$reader->XML($content, null, \LIBXML_NONET | \LIBXML_NOENT)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return null;
        }

        $inTrk = false;
        $inName = false;

        while ($reader->read()) {
            if (\XMLReader::ELEMENT === $reader->nodeType && 'trk' === $reader->localName) {
                $inTrk = true;
            } elseif ($inTrk && \XMLReader::ELEMENT === $reader->nodeType && 'name' === $reader->localName) {
                $inName = true;
            } elseif ($inName && \XMLReader::TEXT === $reader->nodeType) {
                $title = trim($reader->value);
                $reader->close();
                libxml_clear_errors();
                libxml_use_internal_errors($previous);

                return '' !== $title ? $title : null;
            } elseif (\XMLReader::END_ELEMENT === $reader->nodeType && 'name' === $reader->localName) {
                $inName = false;
            } elseif (\XMLReader::ELEMENT === $reader->nodeType && 'trkpt' === $reader->localName) {
                // Stop searching once we reach track points (name comes before trkpt)
                break;
            }
        }

        $reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return null;
    }

    /**
     * Parses a GPX string and returns all track points.
     * Uses XMLReader for O(constant) memory usage.
     * Protected against XXE with LIBXML_NONET | LIBXML_NOENT.
     *
     * {@inheritdoc}
     */
    public function parse(string $content): array
    {
        $points = [];

        $previous = libxml_use_internal_errors(true);

        $reader = new \XMLReader();

        $options = \LIBXML_NONET | \LIBXML_NOENT;

        if (!$reader->XML($content, null, $options)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            throw new \RuntimeException('Failed to initialize XMLReader for GPX content.');
        }

        $inTrkpt = false;
        $lat = 0.0;
        $lon = 0.0;
        $ele = 0.0;
        $inEle = false;

        while ($reader->read()) {
            if (\XMLReader::ELEMENT === $reader->nodeType && 'trkpt' === $reader->localName) {
                $inTrkpt = true;
                $inEle = false;
                $lat = (float) $reader->getAttribute('lat');
                $lon = (float) $reader->getAttribute('lon');
                $ele = 0.0;

                if ($reader->isEmptyElement) {
                    $points[] = new Coordinate($lat, $lon, $ele);
                    $inTrkpt = false;
                }
            } elseif ($inTrkpt && \XMLReader::ELEMENT === $reader->nodeType && 'ele' === $reader->localName) {
                $inEle = true;
            } elseif ($inEle && \XMLReader::TEXT === $reader->nodeType) {
                $ele = (float) $reader->value;
            } elseif (\XMLReader::END_ELEMENT === $reader->nodeType && 'ele' === $reader->localName) {
                $inEle = false;
            } elseif (\XMLReader::END_ELEMENT === $reader->nodeType && 'trkpt' === $reader->localName) {
                $points[] = new Coordinate($lat, $lon, $ele);
                $inTrkpt = false;
                $ele = 0.0;
            }
        }

        $reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $points;
    }
}
