<?php

declare(strict_types=1);

namespace App\InRide;

use App\Format\OsmContactTags;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeoPoint;
use App\Osm\CoverageRepositoryInterface;
use App\Poi\PoiLabelResolver;
use App\Repository\TripRequestRepositoryInterface;

/**
 * AI-free in-ride orchestrator: reads the nearest POIs of one intent category
 * from the Tier-1 index, filters them by opening hours, approximates the detour
 * to reach them and ranks the best three — the middle stages the deleted
 * InRideAssistant ran between an LLM classifier and an LLM narrator.
 *
 * Pipeline (see issue #933): coverage short-circuit -> PostGIS read (name,
 * shelter-type and VAE filters already pushed to SQL by #930) -> de-duplication
 * -> localised label for nameless rows -> tri-state opening filter -> distance
 * sort capped at 10 -> detour on those 10 only -> combined distance+detour
 * ranking capped at 3.
 *
 * @phpstan-type Candidate array{osmType: string, osmId: int, name: string, lat: float, lon: float, distanceMeters: float, openingHoursToday: ?string, closesAt: ?\DateTimeImmutable, phone: ?string, warning: ?PoiWarning, warningMinutes: ?int, detourMeters: ?float}
 */
final readonly class NearbyPoiFinder
{
    private const int PROVISIONAL_CANDIDATES = 10;

    private const int MAX_SUGGESTIONS = 3;

    /**
     * Detour weight in the ranking score: a POI reached with a detour is
     * penalised twice as heavily as raw distance, so a slightly farther POI on
     * the route beats a closer one that requires backtracking.
     */
    private const float DETOUR_WEIGHT = 2.0;

    private const int CLOSES_SOON_MINUTES = 30;

    public function __construct(
        private InRidePoiRepositoryInterface $poiRepository,
        private OpeningHoursParser $openingHoursParser,
        private DetourCalculator $detourCalculator,
        private RouteTail $routeTail,
        private DeeplinkBuilder $deeplinkBuilder,
        private GeoDistanceInterface $distance,
        private CoverageRepositoryInterface $coverageRepository,
        private PoiLabelResolver $labelResolver,
        private TripRequestRepositoryInterface $tripRepository,
    ) {
    }

    /**
     * @return array{outOfCoverage: bool, pois: list<PoiSuggestion>, totalFound: int, capReached: bool}
     */
    public function find(
        InRidePoiCategory $category,
        GeoPoint $position,
        ?int $radiusMeters = null,
        ?string $tripId = null,
        ?int $stageDay = null,
        ?\DateTimeImmutable $now = null,
    ): array {
        if ($this->coverageRepository->isRouteOutOfZone([['lat' => $position->lat, 'lon' => $position->lon]])) {
            return ['outOfCoverage' => true, 'pois' => [], 'totalFound' => 0, 'capReached' => false];
        }

        $now ??= new \DateTimeImmutable('now');
        $locale = (null !== $tripId ? $this->tripRepository->getLocale($tripId) : null) ?? 'en';

        $rows = $this->poiRepository->findNearby(
            $position->lat,
            $position->lon,
            $category->clampRadius($radiusMeters),
            $category,
        );
        $capReached = \count($rows) === $category->candidateLimit();

        $candidates = $this->retain($rows, $category, $position, $locale, $now);
        $totalFound = \count($candidates);

        usort($candidates, static fn (array $a, array $b): int => $a['distanceMeters'] <=> $b['distanceMeters']);
        $candidates = \array_slice($candidates, 0, self::PROVISIONAL_CANDIDATES);

        $candidates = $this->addDetour($candidates, $position, $tripId, $stageDay);

        usort($candidates, fn (array $a, array $b): int => $this->score($a) <=> $this->score($b));

        $pois = array_map(
            fn (array $candidate): PoiSuggestion => $this->toSuggestion($candidate, $category, $position),
            \array_slice($candidates, 0, self::MAX_SUGGESTIONS),
        );

        return ['outOfCoverage' => false, 'pois' => $pois, 'totalFound' => $totalFound, 'capReached' => $capReached];
    }

    /**
     * Steps 3-5: de-duplicate on (osmType, osmId), label nameless rows and drop
     * only the rows certainly closed now.
     *
     * @param list<array{osmType: string, osmId: int, name: ?string, category: string, lat: float, lon: float, openingHours: ?string, tags: array<string, string>}> $rows
     *
     * @return list<Candidate>
     */
    private function retain(array $rows, InRidePoiCategory $category, GeoPoint $position, string $locale, \DateTimeImmutable $now): array
    {
        $candidates = [];
        $seen = [];

        foreach ($rows as $row) {
            $key = $row['osmType'].':'.$row['osmId'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            // Belt-and-suspenders on the SQL name filter (#930): a category where
            // a nameless row is not actionable (food, mechanic, health, train) is
            // dropped here too, so the unit is correct even fed an unfiltered row.
            $name = $this->resolveName($row, $category, $locale);
            if (null === $name) {
                continue;
            }

            $tag = $row['openingHours'];
            $status = null === $tag ? OpeningStatus::UNKNOWN : $this->openingHoursParser->status($tag, $now);
            if (OpeningStatus::CLOSED === $status) {
                continue;
            }

            $warning = null;
            $warningMinutes = null;
            $closesAt = null;

            if (OpeningStatus::UNKNOWN === $status) {
                $warning = PoiWarning::HOURS_UNVERIFIED;
            } elseif (null !== $tag) {
                $closesAt = $this->openingHoursParser->closesAt($tag, $now);
                if ($closesAt instanceof \DateTimeImmutable) {
                    $minutes = (int) floor(($closesAt->getTimestamp() - $now->getTimestamp()) / 60);
                    if ($minutes <= self::CLOSES_SOON_MINUTES) {
                        $warning = PoiWarning::CLOSES_SOON;
                        $warningMinutes = max(1, $minutes);
                    }
                }
            }

            $candidates[] = [
                'osmType' => $row['osmType'],
                'osmId' => $row['osmId'],
                'name' => $name,
                'lat' => $row['lat'],
                'lon' => $row['lon'],
                'distanceMeters' => $this->distance->inMeters($position->lat, $position->lon, $row['lat'], $row['lon']),
                'openingHoursToday' => $tag,
                'closesAt' => $closesAt,
                'phone' => OsmContactTags::phone($row['tags']),
                'warning' => $warning,
                'warningMinutes' => $warningMinutes,
                'detourMeters' => null,
            ];
        }

        return $candidates;
    }

    /**
     * Step 4: the source name, else the tag name, else a localised category
     * label. A category where a nameless row is not actionable
     * ({@see InRidePoiCategory::requiresName()}) returns `null` to signal a
     * drop. A bus shelter (`shelter_type=public_transport`) is labelled
     * distinctly ("bus shelter") rather than as a generic shelter.
     *
     * @param array{osmType: string, osmId: int, name: ?string, category: string, lat: float, lon: float, openingHours: ?string, tags: array<string, string>} $row
     */
    private function resolveName(array $row, InRidePoiCategory $category, string $locale): ?string
    {
        $name = $row['name'] ?? ($row['tags']['name'] ?? null);
        if (null !== $name && '' !== trim($name)) {
            return $name;
        }

        if ($category->requiresName()) {
            return null;
        }

        $rawKey = 'public_transport' === ($row['tags']['shelter_type'] ?? null) ? 'shelter_bus' : $row['category'];

        return $this->labelResolver->displayName($rawKey, $locale);
    }

    /**
     * Step 8: detour on the (at most 10) provisional candidates only — projecting
     * every candidate on a ~1500-point polyline is out of budget at
     * candidateLimit scale. Without a stage geometry the detour stays `null` and
     * ranking falls back to distance alone.
     *
     * @param list<Candidate> $candidates
     *
     * @return list<Candidate>
     */
    private function addDetour(array $candidates, GeoPoint $position, ?string $tripId, ?int $stageDay): array
    {
        if (null === $tripId || null === $stageDay) {
            return $candidates;
        }

        $geometry = $this->tripRepository->getStageGeometry($tripId, $stageDay);
        if (null === $geometry || [] === $geometry) {
            return $candidates;
        }

        $polyline = array_map(static fn (array $point): GeoPoint => new GeoPoint($point['lat'], $point['lon']), $geometry);
        $tail = $this->routeTail->from($position, $polyline);
        if ([] === $tail) {
            return $candidates;
        }

        foreach ($candidates as $index => $candidate) {
            $result = $this->detourCalculator->calculate($position, new GeoPoint($candidate['lat'], $candidate['lon']), $tail);
            $candidates[$index]['detourMeters'] = $result->detourMeters;
            if ($result->poiFarFromRoute && null === $candidate['warning']) {
                $candidates[$index]['warning'] = PoiWarning::FAR_FROM_ROUTE;
            }
        }

        return $candidates;
    }

    /**
     * @param Candidate $candidate
     */
    private function toSuggestion(array $candidate, InRidePoiCategory $category, GeoPoint $position): PoiSuggestion
    {
        $poiPoint = new GeoPoint($candidate['lat'], $candidate['lon']);

        return new PoiSuggestion(
            name: $candidate['name'],
            category: $category,
            osmType: $candidate['osmType'],
            osmId: $candidate['osmId'],
            lat: $candidate['lat'],
            lon: $candidate['lon'],
            distanceMeters: $candidate['distanceMeters'],
            detourMeters: $candidate['detourMeters'],
            openingHoursToday: $candidate['openingHoursToday'],
            closesAt: $candidate['closesAt'],
            phone: $candidate['phone'],
            deeplink: $this->deeplinkBuilder->googleMapsBicycling($position, $poiPoint),
            warning: $candidate['warning'],
            warningMinutes: $candidate['warningMinutes'],
        );
    }

    /**
     * @param Candidate $candidate
     */
    private function score(array $candidate): float
    {
        return $candidate['distanceMeters'] + self::DETOUR_WEIGHT * ($candidate['detourMeters'] ?? 0.0);
    }
}
