<?php

declare(strict_types=1);

namespace App\Llm;

use App\ApiResource\TripRequest;
use App\Llm\Dto\ChatAction;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Parses the raw JSON emitted by LLaMA 3B (via the `dialogue` system prompt) into
 * a typed {@see ChatAction} that the chat processor can dispatch.
 *
 * Robustness goals:
 * - Tolerant to LLM noise: leading/trailing prose, Markdown code fences, smart quotes.
 * - Never throws: invalid or unsupported payloads degrade to a safe `info` fallback
 *   so the chat endpoint always returns a usable response.
 * - Strict on action vocabulary: only the actions whitelisted in
 *   {@see ChatAction::SUPPORTED_ACTIONS} are propagated; everything else collapses
 *   to `info` with the model's `response` preserved.
 */
final readonly class ChatActionInterpreter
{
    public const string DEFAULT_FALLBACK_MESSAGE = "Je n'ai pas compris la demande. Pourriez-vous préciser ?";

    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Parses the raw assistant content (already extracted from the Ollama envelope).
     *
     * @param string $rawContent Raw text emitted by the model (expected to be a JSON object)
     */
    public function interpret(string $rawContent): ChatAction
    {
        $payload = $this->decodeJson($rawContent);

        if (null === $payload) {
            $this->logger->warning('Chat LLM response is not valid JSON.', [
                'raw' => $this->truncate($rawContent),
            ]);

            return $this->fallback(self::DEFAULT_FALLBACK_MESSAGE);
        }

        $action = \is_string($payload['action'] ?? null) ? trim($payload['action']) : '';
        $response = \is_string($payload['response'] ?? null) ? trim($payload['response']) : '';
        $params = \is_array($payload['params'] ?? null) ? $payload['params'] : [];

        if ('' === $response) {
            $response = self::DEFAULT_FALLBACK_MESSAGE;
        }

        if (!\in_array($action, ChatAction::SUPPORTED_ACTIONS, true)) {
            $this->logger->info('Chat LLM produced an unsupported action — falling back to info.', [
                'action' => $action,
            ]);

            return new ChatAction(ChatAction::ACTION_INFO, [], $response);
        }

        $normalisedParams = $this->normaliseParams($action, $params);

        if (null === $normalisedParams) {
            $this->logger->info('Chat LLM produced invalid params for action — falling back to info.', [
                'action' => $action,
            ]);

            return new ChatAction(ChatAction::ACTION_INFO, [], $response);
        }

        return new ChatAction($action, $normalisedParams, $response);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $rawContent): ?array
    {
        $candidate = trim($rawContent);

        if ('' === $candidate) {
            return null;
        }

        // Strip Markdown code fences (```json ... ```) when present.
        if (str_starts_with($candidate, '```')) {
            $candidate = preg_replace('/^```(?:json)?\s*/i', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/\s*```$/', '', $candidate) ?? $candidate;
            $candidate = trim($candidate);
        }

        // Extract the first {...} block when the model wraps it in prose.
        if (!str_starts_with($candidate, '{')) {
            $start = strpos($candidate, '{');
            $end = strrpos($candidate, '}');
            if (false === $start || false === $end || $end <= $start) {
                return null;
            }

            $candidate = substr($candidate, $start, $end - $start + 1);
        }

        try {
            $decoded = json_decode($candidate, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (!\is_string($key)) {
                return null;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null returns null when the params are invalid for the given action
     */
    private function normaliseParams(string $action, array $params): ?array
    {
        return match ($action) {
            ChatAction::ACTION_SPLIT_STAGE => $this->normaliseStageOnly($params),
            ChatAction::ACTION_MERGE_STAGES => $this->normaliseMergeStages($params),
            ChatAction::ACTION_ADD_WAYPOINT => $this->normaliseAddWaypoint($params),
            ChatAction::ACTION_CHANGE_ACCOMMODATION => $this->normaliseChangeAccommodation($params),
            ChatAction::ACTION_ADJUST_DISTANCE => $this->normaliseAdjustDistance($params),
            ChatAction::ACTION_INFO => [],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{stage: int}|null
     */
    private function normaliseStageOnly(array $params): ?array
    {
        $stage = $this->toPositiveInt($params['stage'] ?? null);

        return null === $stage ? null : ['stage' => $stage];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{stages: array{int, int}}|null
     */
    private function normaliseMergeStages(array $params): ?array
    {
        $raw = $params['stages'] ?? null;
        if (!\is_array($raw) || 2 !== \count($raw)) {
            return null;
        }

        $values = array_values($raw);
        $first = $this->toPositiveInt($values[0]);
        $second = $this->toPositiveInt($values[1]);

        if (null === $first || null === $second) {
            return null;
        }

        // Accept either order — the dialogue prompt asks for consecutive
        // stages but does not constrain which one comes first. Downstream
        // consumers normalise the surviving stage via min() before
        // dispatching the recomputation.
        if (1 !== abs($first - $second)) {
            return null;
        }

        return ['stages' => [$first, $second]];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{name: string, stage: int|null}|null
     */
    private function normaliseAddWaypoint(array $params): ?array
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            return null;
        }

        $stage = null;
        if (\array_key_exists('stage', $params) && null !== $params['stage']) {
            $stage = $this->toPositiveInt($params['stage']);
            if (null === $stage) {
                return null;
            }
        }

        return ['name' => trim($name), 'stage' => $stage];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{stage: int, type: string}|null
     */
    private function normaliseChangeAccommodation(array $params): ?array
    {
        $stage = $this->toPositiveInt($params['stage'] ?? null);
        $type = $params['type'] ?? null;

        if (null === $stage || !\is_string($type)) {
            return null;
        }

        $normalised = strtolower(trim($type));
        if (!\in_array($normalised, TripRequest::ALL_ACCOMMODATION_TYPES, true)) {
            return null;
        }

        return ['stage' => $stage, 'type' => $normalised];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{stage: int, km: float}|null
     */
    private function normaliseAdjustDistance(array $params): ?array
    {
        $stage = $this->toPositiveInt($params['stage'] ?? null);
        $km = $params['km'] ?? null;

        if (null === $stage) {
            return null;
        }

        if (!\is_int($km) && !\is_float($km)) {
            return null;
        }

        $kmFloat = (float) $km;
        if ($kmFloat <= 0.0) {
            return null;
        }

        return ['stage' => $stage, 'km' => $kmFloat];
    }

    private function toPositiveInt(mixed $value): ?int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }

        if (\is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }

    private function fallback(string $message): ChatAction
    {
        return new ChatAction(ChatAction::ACTION_INFO, [], $message);
    }

    private function truncate(string $value): string
    {
        return mb_strlen($value) > 500 ? mb_substr($value, 0, 500).'…' : $value;
    }
}
