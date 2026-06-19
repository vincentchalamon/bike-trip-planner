<?php

declare(strict_types=1);

namespace App\Generation;

use App\Llm\Exception\AiUnavailableException;
use App\ApiResource\Model\Coordinate;
use App\Geo\GeocoderInterface;
use App\Llm\ResolvedLlmClient;
use App\Llm\SystemPromptLoader;
use App\Osm\CoverageRepositoryInterface;
use App\Routing\RoutingProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns a natural-language brief into a routed bikepacking itinerary (B1, ADR-042):
 *
 *   brief → LLM (user's provider) → structured spec + named waypoints
 *   → geocode (France + Benelux) → coverage guard → Valhalla (loop/point-to-point)
 *   → if the routed distance is far from the requested target, ONE corrective
 *     re-prompt, then accept and report the real distance.
 *
 * The LLM never produces geometry — it only names places; Valhalla draws the real
 * route. The model is asked to flag out-of-zone briefs (instead of silently
 * relocating, per the B0 spike), and the coverage guard is the backstop.
 *
 * Stateless: returns an {@see AiGeneratedRoute}; persistence + the async pipeline
 * dispatch live in the caller.
 */
final readonly class AiTripGenerationService
{
    public const string PROMPT_NAME = 'itinerary-generation';

    /**
     * A routed distance outside [60%, 140%] of the requested target triggers the
     * single corrective re-prompt.
     */
    private const float TARGET_TOLERANCE = 0.4;

    public function __construct(
        private SystemPromptLoader $promptLoader,
        private GeocoderInterface $geocoder,
        private CoverageRepositoryInterface $coverageRepository,
        private RoutingProviderInterface $routingProvider,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AiUnavailableException when the provider is unreachable
     */
    public function generate(string $brief, ResolvedLlmClient $resolved, string $locale = 'fr'): AiGeneratedRoute
    {
        $systemPrompt = $this->promptLoader->load(self::PROMPT_NAME, ['language' => $locale]);
        $model = $resolved->provider->analysisModel();

        $spec = $this->extractSpec($resolved, $model, $systemPrompt, $brief);
        if (null === $spec) {
            return AiGeneratedRoute::unparseable($locale);
        }

        if (true === ($spec['out_of_zone'] ?? false)) {
            return AiGeneratedRoute::outOfZone($this->specString($spec, 'out_of_zone_reason'), $locale);
        }

        $route = $this->route($spec, $locale);

        // One corrective re-prompt when the routed distance is far off the target.
        if ($route->isSuccess() && $this->isOffTarget($spec, $route->distanceKm)) {
            $this->logger->info('AI generation off-target, attempting one correction.', [
                'routedKm' => round($route->distanceKm, 1),
                'targetKm' => $this->targetKm($spec),
            ]);

            $corrected = $this->extractSpec($resolved, $model, $systemPrompt, $this->correctionBrief($brief, $spec, $route->distanceKm));
            if (null !== $corrected && true !== ($corrected['out_of_zone'] ?? false)) {
                $second = $this->route($corrected, $locale);
                if ($second->isSuccess()) {
                    return $second;
                }
            }
        }

        return $route;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function route(array $spec, string $locale): AiGeneratedRoute
    {
        $loop = (bool) ($spec['loop'] ?? false);

        $places = [$this->specString($spec, 'start')];
        foreach ($this->stringList($spec['waypoints'] ?? null) as $waypoint) {
            $places[] = $waypoint;
        }

        $end = $this->specString($spec, 'end');
        if (!$loop && '' !== $end) {
            $places[] = $end;
        }

        $coordinates = [];
        $missing = [];
        foreach ($places as $place) {
            if ('' === trim($place)) {
                continue;
            }

            $coordinate = $this->geocoder->geocode($place);
            if (!$coordinate instanceof Coordinate) {
                $missing[] = $place;

                continue;
            }

            $coordinates[] = $coordinate;
        }

        if ([] !== $missing) {
            $places = implode(', ', $missing);

            return AiGeneratedRoute::ungeocodable($spec, 'fr' === $locale
                ? \sprintf('Impossible de localiser : %s. Reformulez avec des lieux plus précis.', $places)
                : \sprintf('Could not locate: %s. Try more specific place names.', $places));
        }

        if (\count($coordinates) < 2) {
            return AiGeneratedRoute::ungeocodable($spec, 'fr' === $locale
                ? 'Pas assez de lieux exploitables pour tracer un itinéraire.'
                : 'Not enough usable places to draw an itinerary.');
        }

        // Coverage backstop: even an in-zone start can route through an uncovered
        // area; reject the whole route rather than serve a partial one.
        if ($this->coverageRepository->isRouteOutOfZone(array_map(
            static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon],
            $coordinates,
        ))) {
            return AiGeneratedRoute::outOfZone('fr' === $locale
                ? "L'itinéraire proposé sort de la zone couverte (France et Benelux)."
                : 'The proposed route leaves the covered area (France and the Benelux).', $locale);
        }

        $from = $coordinates[0];
        $to = $loop ? $coordinates[0] : $coordinates[\count($coordinates) - 1];
        $via = $loop ? \array_slice($coordinates, 1) : \array_slice($coordinates, 1, -1);

        try {
            $result = $this->routingProvider->calculateRoute($from, $to, $via);
        } catch (\Throwable $throwable) {
            $this->logger->warning('AI generation routing failed.', ['error' => $throwable->getMessage()]);

            return AiGeneratedRoute::routingFailed($spec, $locale);
        }

        return AiGeneratedRoute::success($spec, $result->coordinates, $result->distance / 1000);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractSpec(ResolvedLlmClient $resolved, string $model, string $systemPrompt, string $userMessage): ?array
    {
        $response = $resolved->client->generate($model, $userMessage, $systemPrompt);
        $raw = $response['response'] ?? null;
        if (!\is_string($raw)) {
            return null;
        }

        return $this->parseJson($raw);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $raw): ?array
    {
        $candidate = trim($raw);
        if (str_starts_with($candidate, '```')) {
            $candidate = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($candidate));
        }

        $start = strpos($candidate, '{');
        $end = strrpos($candidate, '}');
        if (false === $start || false === $end || $end <= $start) {
            return null;
        }

        try {
            $decoded = json_decode(substr($candidate, $start, $end - $start + 1), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        // Re-key to string keys (JSON object keys always are) so the spec is a
        // well-typed array<string, mixed> for the accessors below.
        $spec = [];
        foreach ($decoded as $key => $value) {
            $spec[(string) $key] = $value;
        }

        return $spec;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function specString(array $spec, string $key): string
    {
        $value = $spec[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function specInt(array $spec, string $key): int
    {
        $value = $spec[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function isOffTarget(array $spec, float $routedKm): bool
    {
        $target = $this->targetKm($spec);

        return $target > 0 && ($routedKm < $target * (1 - self::TARGET_TOLERANCE) || $routedKm > $target * (1 + self::TARGET_TOLERANCE));
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function targetKm(array $spec): int
    {
        return max(1, $this->specInt($spec, 'days')) * max(0, $this->specInt($spec, 'km_per_day'));
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function correctionBrief(string $brief, array $spec, float $routedKm): string
    {
        return \sprintf(
            "%s\n\n[correction] Ta proposition précédente faisait %.0f km au total, alors que la cible est ~%d km (%d jours x %d km/jour). Ajuste les waypoints (ajoute, retire ou déplace des étapes) pour t'approcher de la cible, en restant en France ou au Benelux. Réponds uniquement avec le JSON.",
            $brief,
            $routedKm,
            $this->targetKm($spec),
            max(1, $this->specInt($spec, 'days')),
            max(0, $this->specInt($spec, 'km_per_day')),
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
