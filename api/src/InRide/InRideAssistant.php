<?php

declare(strict_types=1);

namespace App\InRide;

use App\ApiResource\Model\GeoPosition;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeoPoint;
use App\Llm\Exception\AiUnavailableException;
use App\Llm\ResolvedLlmClient;
use App\Llm\SystemPromptLoader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * In-ride conversational assistant — the orchestrator that turns a free-text
 * message into a list of nearby, actionable POI suggestions plus a markdown
 * narrative.
 *
 * Pipeline:
 *  1. {@see PoiIntentDetector} classifies the user message into a structured
 *     intent (category + radius + optional opening-hours constraint).
 *  2. {@see InRidePoiRepositoryInterface} reads the matching features from the
 *     local-first Tier-1 PostGIS index (ADR-040) around the rider position — no
 *     runtime Overpass call, so no cache is needed.
 *  3. Opening hours are validated via {@see OpeningHoursParser}. When the
 *     intent carries an `open_for_minutes` constraint, only POIs that remain
 *     open at least that long are kept.
 *  4. {@see DetourCalculator} approximates the detour required to visit each
 *     POI. The remaining-route polyline is provided by the caller (the
 *     frontend store keeps it in memory); when none is supplied the detour
 *     stays at 0 because there is no rejoin point to measure against.
 *  5. Top-3 POIs are picked using a score of "proximity × low-detour" and
 *     enriched with {@see DeeplinkBuilder} navigation URLs.
 *  6. The LLM is given a deterministic JSON view of the selected POIs (via the
 *     `in-ride` system prompt) and returns a short markdown narrative shown in
 *     the chat bubble.
 *
 * The orchestrator is defensive: a missing/disabled LLM or an unparseable
 * opening_hours tag never raise — they degrade to a sensible narrative so the
 * rider always gets a useful answer.
 */
final readonly class InRideAssistant
{
    private const int MAX_SUGGESTIONS = 3;

    /**
     * Detour weight in the ranking score. The score is
     * `distance + DETOUR_WEIGHT * detour`, so detour is penalised twice as
     * heavily as raw distance — visiting a POI a bit further along the route
     * is preferable to a closer one that requires backtracking.
     */
    private const float DETOUR_WEIGHT = 2.0;

    public function __construct(
        private PoiIntentDetector $intentDetector,
        private InRidePoiRepositoryInterface $poiRepository,
        private OpeningHoursParser $openingHoursParser,
        private DetourCalculator $detourCalculator,
        private DeeplinkBuilder $deeplinkBuilder,
        private GeoDistanceInterface $distance,
        private SystemPromptLoader $promptLoader,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<GeoPoint> $remainingRoute Polyline of the rider's remaining itinerary
     */
    public function assist(
        string $message,
        GeoPosition $position,
        ResolvedLlmClient $resolved,
        array $remainingRoute = [],
        ?\DateTimeImmutable $now = null,
    ): InRideResponse {
        $intent = $this->intentDetector->detect($message, $resolved);

        if ($intent->isUnknown()) {
            return new InRideResponse(
                category: PoiSuggestion::CATEGORY_UNKNOWN,
                pois: [],
                narrative: "Je n'ai pas identifié de point d'intérêt à chercher ici. Reformulez en précisant ce que vous cherchez (eau, abri, restaurant, vélociste).",
            );
        }

        $center = new GeoPoint($position->lat, $position->lon);
        $now ??= new \DateTimeImmutable('now');

        $rawElements = $this->fetchPois($center, $intent);

        // When the rider hasn't shared a remaining-route polyline, we have no
        // notion of "rejoining" the planned itinerary — a single-point
        // polyline would yield a detour ≈ 2 × straight-line (out-and-back),
        // which is misleading. Pass an empty polyline so buildSuggestions
        // can record `detour_m = 0` for these rows.
        $suggestions = $this->buildSuggestions($rawElements, $center, $remainingRoute, $intent, $now);

        // Rank by combined "distance + DETOUR_WEIGHT × detour" score.
        usort(
            $suggestions,
            fn (PoiSuggestion $a, PoiSuggestion $b): int => $this->score($a) <=> $this->score($b),
        );

        $top = \array_slice($suggestions, 0, self::MAX_SUGGESTIONS);

        $narrative = $this->generateNarrative($message, $intent, $top, $resolved);

        return new InRideResponse(
            category: $intent->category,
            pois: $top,
            narrative: $narrative,
        );
    }

    /**
     * @return list<array{lat: float, lon: float, tags: array<string, string>}>
     */
    private function fetchPois(GeoPoint $center, PoiIntent $intent): array
    {
        return $this->poiRepository->findNearby($center->lat, $center->lon, $intent->maxDistanceMeters, $intent->category);
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @param list<GeoPoint>             $polyline
     *
     * @return list<PoiSuggestion>
     */
    private function buildSuggestions(array $elements, GeoPoint $center, array $polyline, PoiIntent $intent, \DateTimeImmutable $now): array
    {
        $suggestions = [];

        foreach ($elements as $element) {
            $coordinates = $this->extractCoordinates($element);
            if (null === $coordinates) {
                continue;
            }

            $tags = $element['tags'] ?? [];
            if (!\is_array($tags)) {
                $tags = [];
            }

            /** @var array<string, mixed> $tags */
            $name = \is_string($tags['name'] ?? null) ? trim($tags['name']) : '';
            if ('' === $name) {
                // Food and mechanic POIs without a name leave the rider with
                // nothing to recognise on arrival, so we drop them. For water
                // and shelter, coordinates alone are fully actionable (the
                // deeplink only needs lat/lon); fall back to a generic label
                // so unnamed fountains and bus shelters still surface.
                if (\in_array($intent->category, [PoiSuggestion::CATEGORY_FOOD, PoiSuggestion::CATEGORY_MECHANIC], true)) {
                    continue;
                }

                $name = match ($intent->category) {
                    PoiSuggestion::CATEGORY_WATER => "Point d'eau",
                    PoiSuggestion::CATEGORY_SHELTER => 'Abri',
                    default => 'POI sans nom',
                };
            }

            [$lat, $lon] = $coordinates;
            $poiPoint = new GeoPoint($lat, $lon);

            // Cheap straight-line prefilter before the per-segment detour scan.
            // With up to 50 Overpass elements × hundreds of polyline segments,
            // calling DetourCalculator unconditionally can blow past the 1 s
            // in-ride budget. We drop any POI further than `2 × maxDistanceMeters`
            // from the rider — twice the intent radius leaves room for POIs the
            // rider would still tolerate but rejects clearly out-of-scope ones.
            $straightLine = $this->distance->inMeters($center->lat, $center->lon, $poiPoint->lat, $poiPoint->lon);
            if ($straightLine > 2 * $intent->maxDistanceMeters) {
                continue;
            }

            $openingHoursTag = \is_string($tags['opening_hours'] ?? null) ? $tags['opening_hours'] : null;
            $closesAt = null;
            $warning = null;

            if (null !== $openingHoursTag && '' !== trim($openingHoursTag)) {
                if (!$this->openingHoursParser->isOpenNow($openingHoursTag, $now)) {
                    continue;
                }

                $closesAt = $this->openingHoursParser->closesAt($openingHoursTag, $now);

                if (null !== $intent->openForMinutes) {
                    $duration = new \DateInterval(\sprintf('PT%dM', $intent->openForMinutes));
                    if (!$this->openingHoursParser->isOpenForAtLeast($openingHoursTag, $now, $duration)) {
                        continue;
                    }
                }

                if ($closesAt instanceof \DateTimeImmutable) {
                    $minutesToClose = (int) floor(($closesAt->getTimestamp() - $now->getTimestamp()) / 60);
                    if ($minutesToClose <= 30) {
                        $warning = \sprintf('Ferme dans %d min', max(1, $minutesToClose));
                    }
                }
            }

            if ([] === $polyline) {
                // Without a planned itinerary we have no rejoin point to
                // measure against, so the "detour" cost is unknown — surface
                // it as 0 instead of fabricating a 2× round-trip estimate.
                $detourMeters = 0.0;
            } else {
                $detour = $this->detourCalculator->calculate($center, $poiPoint, $polyline);
                $detourMeters = $detour->detourMeters;
                if ($detour->poiFarFromRoute && null === $warning) {
                    $warning = 'Éloigné de l\'itinéraire';
                }
            }

            $deeplink = $this->deeplinkBuilder->googleMapsBicycling($center, $poiPoint);

            $suggestions[] = new PoiSuggestion(
                name: $name,
                category: $intent->category,
                lat: $poiPoint->lat,
                lon: $poiPoint->lon,
                distanceMeters: $straightLine,
                detourMeters: $detourMeters,
                openingHoursToday: $openingHoursTag,
                closesAt: $closesAt,
                phone: \is_string($tags['phone'] ?? null) ? $tags['phone'] : (\is_string($tags['contact:phone'] ?? null) ? $tags['contact:phone'] : null),
                deeplink: $deeplink,
                warning: $warning,
            );
        }

        return $suggestions;
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return array{0: float, 1: float}|null
     */
    private function extractCoordinates(array $element): ?array
    {
        if (isset($element['lat'], $element['lon']) && \is_numeric($element['lat']) && \is_numeric($element['lon'])) {
            return [(float) $element['lat'], (float) $element['lon']];
        }

        return null;
    }

    private function score(PoiSuggestion $suggestion): float
    {
        return $suggestion->distanceMeters + self::DETOUR_WEIGHT * $suggestion->detourMeters;
    }

    /**
     * @param list<PoiSuggestion> $pois
     */
    private function generateNarrative(string $message, PoiIntent $intent, array $pois, ResolvedLlmClient $resolved): string
    {
        if ([] === $pois) {
            return \sprintf(
                "Je n'ai rien trouvé d'ouvert dans un rayon de %d km. Essayez d'élargir la recherche ou changez de catégorie.",
                (int) round($intent->maxDistanceMeters / 1000),
            );
        }

        try {
            $systemPrompt = $this->promptLoader->load('in-ride');
        } catch (\Throwable $throwable) {
            $this->logger->warning('InRideAssistant: failed to load in-ride prompt, falling back to plain narrative.', [
                'error' => $throwable->getMessage(),
            ]);

            return $this->fallbackNarrative($pois);
        }

        $payload = [
            'message' => $message,
            'category' => $intent->category,
            'pois' => array_map(static fn (PoiSuggestion $p): array => $p->toArray(), $pois),
        ];

        try {
            $jsonPayload = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $jsonException) {
            $this->logger->warning('InRideAssistant: failed to encode payload for narrative.', [
                'error' => $jsonException->getMessage(),
            ]);

            return $this->fallbackNarrative($pois);
        }

        try {
            $response = $resolved->client->generate(
                $resolved->provider->chatModel(),
                $jsonPayload,
                $systemPrompt,
                ['temperature' => 0.3],
            );
        } catch (AiUnavailableException $aiUnavailableException) {
            $this->logger->critical('InRideAssistant: AI provider unavailable for narrative, using fallback.', [
                'reason' => $aiUnavailableException->getReason()->value,
                'error' => $aiUnavailableException->getMessage(),
            ]);

            return $this->fallbackNarrative($pois);
        }

        if (null === $response) {
            return $this->fallbackNarrative($pois);
        }

        $content = $response['response'] ?? null;
        if (!\is_string($content) || '' === trim($content)) {
            return $this->fallbackNarrative($pois);
        }

        return $this->extractNarrative($content) ?? $this->fallbackNarrative($pois);
    }

    /**
     * Parses a JSON envelope `{"narrative": "..."}` emitted by the LLM. Falls back
     * to the raw content when the model returned plain Markdown instead.
     */
    private function extractNarrative(string $raw): ?string
    {
        $candidate = trim($raw);
        if ('' === $candidate) {
            return null;
        }

        if (!str_starts_with($candidate, '{')) {
            // Plain markdown response: accept as-is.
            return $candidate;
        }

        try {
            $decoded = json_decode($candidate, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $candidate;
        }

        if (\is_array($decoded) && isset($decoded['narrative']) && \is_string($decoded['narrative'])) {
            $narrative = trim($decoded['narrative']);

            return '' === $narrative ? null : $narrative;
        }

        return null;
    }

    /**
     * @param list<PoiSuggestion> $pois
     */
    private function fallbackNarrative(array $pois): string
    {
        $lines = ['Voici les options les plus proches :'];
        foreach ($pois as $poi) {
            $distance = $poi->distanceMeters >= 1000
                ? \sprintf('%.1f km', $poi->distanceMeters / 1000)
                : \sprintf('%d m', (int) round($poi->distanceMeters));
            $detour = (int) round($poi->detourMeters);

            $line = \sprintf('- **%s** — %s (détour %d m) [Itinéraire](%s)', $poi->name, $distance, $detour, $poi->deeplink);
            if (null !== $poi->warning) {
                $line .= \sprintf(' — %s', $poi->warning);
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
