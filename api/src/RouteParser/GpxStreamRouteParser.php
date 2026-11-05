<?php

declare(strict_types=1);

namespace App\RouteParser;

use XMLReader;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;

final class GpxStreamRouteParser implements GpxRouteParserInterface
{
    /**
     * Rejects any GPX carrying a DOCTYPE. Legitimate GPX is schema-based and
     * never declares a DTD, so a DOCTYPE only ever introduces entity/XXE
     * vectors — refusing it up front is the first XXE defense layer.
     */
    private function hasDoctype(string $content): bool
    {
        return 1 === preg_match('/<!DOCTYPE/i', $content);
    }

    /**
     * The byte check above only sees an ASCII-compatible encoding; a UTF-16 document spells
     * the same DOCTYPE with interleaved null bytes. The reader decodes whatever encoding the
     * document declares, so the node type is the check that holds for all of them.
     */
    private function isDoctypeNode(\XMLReader $reader): bool
    {
        return \XMLReader::DOC_TYPE === $reader->nodeType;
    }

    /**
     * Extracts the first track name from a GPX string.
     * Uses XMLReader for O(constant) memory usage.
     */
    public function extractTitle(string $content): ?string
    {
        if ($this->hasDoctype($content)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        $reader = new \XMLReader();

        // LIBXML_NONET blocks network entity fetches. LIBXML_NOENT is deliberately
        // NOT set: it would enable external-entity substitution (XXE / local-file
        // read via file://), which LIBXML_NONET does not prevent.
        if (!$reader->XML($content, null, \LIBXML_NONET)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return null;
        }

        $inTrk = false;
        $inName = false;

        while ($reader->read()) {
            if ($this->isDoctypeNode($reader)) {
                break;
            }

            if (\XMLReader::ELEMENT === $reader->nodeType && 'trk' === $reader->localName) {
                $inTrk = true;
            } elseif ($inTrk && \XMLReader::ELEMENT === $reader->nodeType && 'name' === $reader->localName) {
                $inName = true;
            } elseif ($inName && \XMLReader::TEXT === $reader->nodeType) {
                $title = trim($reader->value);
                $reader->close();
                libxml_clear_errors();
                libxml_use_internal_errors($previous);

                // Bounded to the column it is stored in: an over-long name would fail the write.
                return '' !== $title ? mb_substr($title, 0, TripRequest::TITLE_MAX_LENGTH) : null;
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
     *
     * XXE hardening: any DOCTYPE is rejected outright, and parsing uses
     * LIBXML_NONET WITHOUT LIBXML_NOENT so external entities are never
     * substituted (LIBXML_NOENT would re-enable file:// entity reads).
     *
     * {@inheritdoc}
     */
    public function parse(string $content): array
    {
        if ($this->hasDoctype($content)) {
            throw new \RuntimeException('GPX documents with a DOCTYPE are not allowed.');
        }

        $points = [];

        $previous = libxml_use_internal_errors(true);

        $reader = new \XMLReader();

        $options = \LIBXML_NONET;

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
            if ($this->isDoctypeNode($reader)) {
                $reader->close();
                libxml_clear_errors();
                libxml_use_internal_errors($previous);

                throw new \RuntimeException('GPX documents with a DOCTYPE are not allowed.');
            }

            if (\XMLReader::ELEMENT === $reader->nodeType && 'trkpt' === $reader->localName) {
                if (\count($points) >= RouteTooLargeException::MAX_POINTS) {
                    $reader->close();
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);

                    throw RouteTooLargeException::tooManyPoints();
                }

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
