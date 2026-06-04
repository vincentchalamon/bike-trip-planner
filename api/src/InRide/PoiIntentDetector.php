<?php

declare(strict_types=1);

namespace App\InRide;

use App\Llm\Exception\OllamaUnavailableException;
use App\Llm\LlmClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Lightweight LLM pass that classifies an in-ride free-text request into a
 * structured intent so the orchestrator can hit Overpass with the right query.
 *
 * Uses LLaMA 3.2:3b with temperature 0 and `num_predict=50` for cheap, near-deterministic
 * classification. The model is asked to emit a strict JSON object:
 *
 *     {
 *       "category": "food" | "shelter" | "water" | "mechanic" | "unknown",
 *       "max_distance_m": <int 2000..5000>,
 *       "opening_filter": { "open_for_minutes": <int> }   // optional
 *     }
 *
 * Defensive parsing: unknown categories collapse to `unknown`, out-of-range
 * distances are clamped to the sensible window, and any LLM error (disabled,
 * unreachable, malformed) degrades to an `unknown` intent so the orchestrator
 * can still produce a graceful response.
 */
final readonly class PoiIntentDetector
{
    public const string MODEL = 'llama3.2:3b';

    public const int MIN_RADIUS_METERS = 2_000;

    public const int MAX_RADIUS_METERS = 5_000;

    public const int DEFAULT_RADIUS_METERS = 3_000;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
You are a routing classifier embedded in a bicycle trip-planning assistant.
You receive a French or English free-text message from a rider mid-ride and you
MUST respond with a single JSON object describing what nearby point of interest
they are looking for.

JSON schema:
{
  "category": "food" | "shelter" | "water" | "mechanic" | "unknown",
  "max_distance_m": integer between 2000 and 5000,
  "opening_filter": { "open_for_minutes": integer }    // OPTIONAL
}

Rules:
- `food`: any request about eating (restaurant, snack, sandwich, frites, café, bakery, ...).
- `shelter`: rain/storm/refuge/abri.
- `water`: drinking water / fontaine / point d'eau.
- `mechanic`: bike shop, repair, puncture, derailleur.
- `unknown`: anything else (route changes, weather questions, small talk).
- Pick a default `max_distance_m` of 3000 unless the rider explicitly insists on
  staying close (use 2000) or accepts a longer detour (up to 5000).
- Add `opening_filter.open_for_minutes` only when the rider expresses a
  temporal constraint ("encore ouvert dans 30 minutes", "still open in an hour").
- Respond with ONLY the JSON object — no Markdown fences, no prose.
PROMPT;

    public function __construct(
        private LlmClientInterface $llmClient,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function detect(string $userMessage): PoiIntent
    {
        $message = trim($userMessage);
        if ('' === $message) {
            return PoiIntent::unknown();
        }

        try {
            $response = $this->llmClient->generate(
                self::MODEL,
                $message,
                self::SYSTEM_PROMPT,
                [
                    'temperature' => 0.0,
                    'num_predict' => 50,
                    'format' => 'json',
                ],
            );
        } catch (OllamaUnavailableException $ollamaUnavailableException) {
            $this->logger->critical('PoiIntentDetector: LLM unavailable, defaulting to unknown intent.', [
                'error' => $ollamaUnavailableException->getMessage(),
            ]);

            return PoiIntent::unknown();
        }

        if (null === $response) {
            return PoiIntent::unknown();
        }

        $rawContent = $this->extractContent($response);
        if (null === $rawContent) {
            $this->logger->info('PoiIntentDetector: empty LLM response, defaulting to unknown intent.');

            return PoiIntent::unknown();
        }

        return $this->parsePayload($rawContent);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractContent(array $response): ?string
    {
        // `/api/generate` returns {"response": "..."}; `/api/chat` returns {"message": {"content": "..."}}.
        if (isset($response['response']) && \is_string($response['response'])) {
            return $response['response'];
        }

        if (isset($response['message']) && \is_array($response['message'])) {
            $content = $response['message']['content'] ?? null;
            if (\is_string($content)) {
                return $content;
            }
        }

        return null;
    }

    private function parsePayload(string $rawContent): PoiIntent
    {
        $candidate = trim($rawContent);
        if ('' === $candidate) {
            return PoiIntent::unknown();
        }

        if (str_starts_with($candidate, '```')) {
            $candidate = preg_replace('/^```(?:json)?\s*/i', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/\s*```$/', '', $candidate) ?? $candidate;
            $candidate = trim($candidate);
        }

        if (!str_starts_with($candidate, '{')) {
            $start = strpos($candidate, '{');
            $end = strrpos($candidate, '}');
            if (false === $start || false === $end || $end <= $start) {
                return PoiIntent::unknown();
            }

            $candidate = substr($candidate, $start, $end - $start + 1);
        }

        try {
            $decoded = json_decode($candidate, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return PoiIntent::unknown();
        }

        if (!\is_array($decoded)) {
            return PoiIntent::unknown();
        }

        $category = \is_string($decoded['category'] ?? null) ? strtolower(trim($decoded['category'])) : '';
        if (!\in_array($category, PoiSuggestion::SUPPORTED_CATEGORIES, true)) {
            return PoiIntent::unknown();
        }

        $rawRadius = $decoded['max_distance_m'] ?? null;
        $radius = self::DEFAULT_RADIUS_METERS;
        if (\is_int($rawRadius)) {
            $radius = $rawRadius;
        } elseif (\is_float($rawRadius)) {
            $radius = (int) round($rawRadius);
        } elseif (\is_string($rawRadius) && ctype_digit($rawRadius)) {
            $radius = (int) $rawRadius;
        }

        $radius = max(self::MIN_RADIUS_METERS, min(self::MAX_RADIUS_METERS, $radius));

        $openForMinutes = null;
        if (isset($decoded['opening_filter']) && \is_array($decoded['opening_filter'])) {
            $rawMinutes = $decoded['opening_filter']['open_for_minutes'] ?? null;
            if (\is_int($rawMinutes) && $rawMinutes > 0) {
                $openForMinutes = $rawMinutes;
            } elseif (\is_float($rawMinutes) && $rawMinutes > 0) {
                $openForMinutes = (int) round($rawMinutes);
            } elseif (\is_string($rawMinutes) && ctype_digit($rawMinutes)) {
                $value = (int) $rawMinutes;
                if ($value > 0) {
                    $openForMinutes = $value;
                }
            }
        }

        return new PoiIntent($category, $radius, $openForMinutes);
    }
}
