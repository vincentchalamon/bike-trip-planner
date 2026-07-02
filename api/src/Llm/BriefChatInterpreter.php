<?php

declare(strict_types=1);

namespace App\Llm;

use App\Llm\Dto\BriefChatReply;

/**
 * Parses the raw text emitted by the `brief-chat` system prompt into a typed
 * {@see BriefChatReply} for the stateless trip-brief chat (ADR-045,
 * `POST /trips/ai-chat`).
 *
 * Mirrors {@see ChatActionInterpreter}'s lenient strategy — tolerant to Markdown
 * code fences and surrounding prose — but produces the brief-chat envelope
 * `{reply, readyToGenerate, collected}` instead of a planning action. On any
 * parse failure it never throws: it falls back to a plain reply carrying the raw
 * model text with `readyToGenerate: false` and an empty `collected`, so the chat
 * endpoint always returns a usable turn.
 */
final readonly class BriefChatInterpreter
{
    /**
     * @param string $rawContent Raw text emitted by the model (expected to be a JSON object)
     */
    public function interpret(string $rawContent): BriefChatReply
    {
        $payload = $this->decodeJson($rawContent);

        if (null === $payload) {
            return new BriefChatReply(reply: trim($rawContent), readyToGenerate: false, collected: []);
        }

        $reply = \is_string($payload['reply'] ?? null) ? trim($payload['reply']) : '';
        if ('' === $reply) {
            // The model returned a JSON object without a usable reply: degrade to
            // the raw text so the rider still sees something.
            $reply = trim($rawContent);
        }

        $readyToGenerate = true === ($payload['readyToGenerate'] ?? null);
        $collected = \is_array($payload['collected'] ?? null) ? $this->normaliseCollected($payload['collected']) : [];

        return new BriefChatReply(reply: $reply, readyToGenerate: $readyToGenerate, collected: $collected);
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

        if (str_starts_with($candidate, '```')) {
            $candidate = preg_replace('/^```(?:json)?\s*/i', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/\s*```$/', '', $candidate) ?? $candidate;
            $candidate = trim($candidate);
        }

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
     * A brief field is a short scalar (a city, a duration, a profile word). This
     * caps string values so a model that leaks its chain-of-thought into a field
     * — Gemini 2.5-flash occasionally dumps reasoning into e.g. `resupply` — cannot
     * pollute the recap or the consolidated generation brief.
     */
    private const int MAX_COLLECTED_VALUE_LENGTH = 120;

    /**
     * Keeps only string-keyed scalar (or null) entries from the model's
     * `collected` map, so the structured recap forwarded to the client stays a
     * flat, predictable shape regardless of model noise. Over-long strings are
     * dropped as noise, not truncated: a paragraph is never a real brief value.
     *
     * @param array<array-key, mixed> $collected
     *
     * @return array<string, scalar|null>
     */
    private function normaliseCollected(array $collected): array
    {
        $result = [];
        foreach ($collected as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }

            if (\is_string($value)) {
                $value = trim($value);
                if ('' === $value || mb_strlen($value) > self::MAX_COLLECTED_VALUE_LENGTH) {
                    continue;
                }

                $result[$key] = $value;
            } elseif (null === $value || \is_scalar($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
