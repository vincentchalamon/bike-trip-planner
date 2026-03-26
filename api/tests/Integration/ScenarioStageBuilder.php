<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use InvalidArgumentException;
use XMLReader;
use RuntimeException;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\RouteParser\GpxStreamRouteParser;

/**
 * Helper to build Stage objects from GPX fixture files for integration tests.
 */
final class ScenarioStageBuilder
{
    /**
     * Parses a GPX file and builds a single Stage from all track points.
     */
    public static function buildFromGpx(string $gpxPath): Stage
    {
        $parser = new GpxStreamRouteParser();
        $points = $parser->parse(self::readFile($gpxPath));

        if ([] === $points) {
            throw new InvalidArgumentException(\sprintf('GPX file "%s" contains no track points.', $gpxPath));
        }

        $distance = self::calculateTotalDistanceKm($points);
        $elevation = self::calculateTotalAscent($points);
        $elevationLoss = self::calculateTotalDescent($points);

        return new Stage(
            tripId: 'scenario-test',
            dayNumber: 1,
            distance: $distance,
            elevation: $elevation,
            startPoint: $points[0],
            endPoint: $points[\count($points) - 1],
            geometry: $points,
            elevationLoss: $elevationLoss,
        );
    }

    /**
     * Parses a GPX file with multiple <trkseg> and builds one Stage per segment.
     *
     * @return list<Stage>
     */
    public static function buildSegmentsFromGpx(string $gpxPath): array
    {
        $segments = self::parseSegments(self::readFile($gpxPath));
        $stages = [];

        foreach ($segments as $i => $points) {
            if ([] === $points) {
                continue;
            }

            $stages[] = new Stage(
                tripId: 'scenario-test',
                dayNumber: $i + 1,
                distance: self::calculateTotalDistanceKm($points),
                elevation: self::calculateTotalAscent($points),
                startPoint: $points[0],
                endPoint: $points[\count($points) - 1],
                geometry: $points,
                elevationLoss: self::calculateTotalDescent($points),
            );
        }

        return $stages;
    }

    /**
     * Calculates the total distance in km using the Haversine formula.
     *
     * @param list<Coordinate> $points
     */
    private static function calculateTotalDistanceKm(array $points): float
    {
        $totalMeters = 0.0;

        for ($i = 1, $count = \count($points); $i < $count; ++$i) {
            $totalMeters += self::haversineMeters(
                $points[$i - 1]->lat,
                $points[$i - 1]->lon,
                $points[$i]->lat,
                $points[$i]->lon,
            );
        }

        return $totalMeters / 1000.0;
    }

    /**
     * Calculates total elevation gain (D+) in meters.
     *
     * @param list<Coordinate> $points
     */
    private static function calculateTotalAscent(array $points): float
    {
        $gain = 0.0;

        for ($i = 1, $count = \count($points); $i < $count; ++$i) {
            $diff = $points[$i]->ele - $points[$i - 1]->ele;
            if ($diff > 0) {
                $gain += $diff;
            }
        }

        return $gain;
    }

    /**
     * Calculates total elevation loss (D-) in meters.
     *
     * @param list<Coordinate> $points
     */
    private static function calculateTotalDescent(array $points): float
    {
        $loss = 0.0;

        for ($i = 1, $count = \count($points); $i < $count; ++$i) {
            $diff = $points[$i]->ele - $points[$i - 1]->ele;
            if ($diff < 0) {
                $loss += abs($diff);
            }
        }

        return $loss;
    }

    /**
     * Haversine distance in meters between two coordinate pairs.
     */
    public static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latDiff = deg2rad($lat2 - $lat1);
        $lonDiff = deg2rad($lon2 - $lon1);
        $a = sin($latDiff / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDiff / 2) ** 2;

        return 6_371_000.0 * 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
    }

    /**
     * Parses GPX content and returns points grouped by <trkseg>.
     *
     * @return list<list<Coordinate>>
     */
    private static function parseSegments(string $content): array
    {
        $segments = [];
        $currentSegment = [];

        $reader = new XMLReader();
        $options = \LIBXML_NONET | \LIBXML_NOENT;

        if (!$reader->XML($content, null, $options)) {
            throw new RuntimeException('Failed to initialize XMLReader for GPX content.');
        }

        $inTrkpt = false;
        $lat = 0.0;
        $lon = 0.0;
        $ele = 0.0;
        $inEle = false;

        libxml_use_internal_errors(true);

        while ($reader->read()) {
            if (XMLReader::ELEMENT === $reader->nodeType && 'trkseg' === $reader->localName) {
                $currentSegment = [];
            } elseif (XMLReader::END_ELEMENT === $reader->nodeType && 'trkseg' === $reader->localName) {
                if ([] !== $currentSegment) {
                    $segments[] = $currentSegment;
                }

                $currentSegment = [];
            } elseif (XMLReader::ELEMENT === $reader->nodeType && 'trkpt' === $reader->localName) {
                $inTrkpt = true;
                $inEle = false;
                $lat = (float) $reader->getAttribute('lat');
                $lon = (float) $reader->getAttribute('lon');
                $ele = 0.0;

                if ($reader->isEmptyElement) {
                    $currentSegment[] = new Coordinate($lat, $lon, $ele);
                    $inTrkpt = false;
                }
            } elseif ($inTrkpt && XMLReader::ELEMENT === $reader->nodeType && 'ele' === $reader->localName) {
                $inEle = true;
            } elseif ($inEle && XMLReader::TEXT === $reader->nodeType) {
                $ele = (float) $reader->value;
            } elseif (XMLReader::END_ELEMENT === $reader->nodeType && 'ele' === $reader->localName) {
                $inEle = false;
            } elseif (XMLReader::END_ELEMENT === $reader->nodeType && 'trkpt' === $reader->localName) {
                $currentSegment[] = new Coordinate($lat, $lon, $ele);
                $inTrkpt = false;
                $ele = 0.0;
            }
        }

        $reader->close();
        libxml_clear_errors();

        return $segments;
    }

    private static function readFile(string $gpxPath): string
    {
        $content = file_get_contents($gpxPath);

        if (false === $content) {
            throw new InvalidArgumentException(\sprintf('Cannot read GPX file: %s', $gpxPath));
        }

        return $content;
    }
}
