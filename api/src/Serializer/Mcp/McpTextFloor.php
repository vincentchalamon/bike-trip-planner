<?php

declare(strict_types=1);

namespace App\Serializer\Mcp;

use App\State\Mcp\ThirdPartyText;
use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * The serializer MCP tool answers go through, and nothing else does.
 *
 * Every string a tool emits is put through {@see ThirdPartyText::hygiene()} once the answer has
 * been normalised: control and format characters removed, line and paragraph separators turned
 * into a space. That is the whole of it, and it is deliberately narrow.
 *
 * **What it holds:** a value this project did not write cannot change the shape of the answer
 * around it. A POI name carrying a newline can no longer forge what looks like the end of one
 * field and the start of another in a client that flattens the answer into text, and that holds
 * for every field — including the ones nobody mapped: the alert payloads their producers
 * publish, the resupply points, `list_trips`, and whatever is added tomorrow. Coverage is the
 * point: a per-field call is a list, and a list is what gets forgotten.
 *
 * **What it does not do, on purpose:**
 *  - it does not read meaning. A sentence that says "ignore your instructions" comes out as the
 *    sentence it is. What bounds a successful injection is the token's scope and the ownership
 *    check on every tool, not the filtering of text (ADR-081, which closes this unit);
 *  - it caps nothing. The answer is JSON-LD, so this walks `@id` and `@context` too, and a cut
 *    there corrupts an identifier; and prose is left whole by a decision recorded twice (a
 *    Wikidata description is not a name). Length is decided per field, where the field is known
 *    — {@see ThirdPartyText::clean()} for labels;
 *  - it never changes a value's type. A string stays a string, empty or not; a key is never
 *    added or removed.
 *
 * Why a serializer of its own rather than a normalizer in the chain: `Serializer` caches its
 * choice of normalizer per `[format][class]`, and the format here is `jsonld` — REST's format
 * too. A cacheable normalizer that checked "is this an MCP tool?" would be asked once and then
 * answer for REST as well, for the life of the worker; a non-cacheable one would be asked for
 * every object the application ever normalises. This one is injected into
 * `StructuredContentProcessor` alone ({@see \App\DependencyInjection\McpTextFloorPass}), and
 * because that processor encodes the very array it normalised, the `TextContent` and the
 * `structuredContent` of an answer are treated by the same pass and cannot disagree.
 */
final readonly class McpTextFloor implements SerializerInterface, NormalizerInterface, EncoderInterface
{
    public function __construct(
        private SerializerInterface&NormalizerInterface&EncoderInterface $serializer,
    ) {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $normalized = $this->serializer->normalize($data, $format, $context);

        return match (true) {
            \is_string($normalized) => ThirdPartyText::hygiene($normalized),
            \is_array($normalized) => array_map(self::floor(...), $normalized),
            default => self::floorInPlace($normalized),
        };
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->serializer->supportsNormalization($data, $format, $context);
    }

    public function getSupportedTypes(?string $format): array
    {
        return $this->serializer->getSupportedTypes($format);
    }

    public function encode(mixed $data, string $format, array $context = []): string
    {
        return $this->serializer->encode($data, $format, $context);
    }

    public function supportsEncoding(string $format): bool
    {
        return $this->serializer->supportsEncoding($format);
    }

    public function serialize(mixed $data, string $format, array $context = []): string
    {
        return $this->encode($this->normalize($data, $format, $context), $format, $context);
    }

    public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
    {
        return $this->serializer->deserialize($data, $type, $format, $context);
    }

    private static function floor(mixed $value): mixed
    {
        return match (true) {
            \is_string($value) => ThirdPartyText::hygiene($value),
            \is_array($value) => array_map(self::floor(...), $value),
            default => self::floorInPlace($value),
        };
    }

    /**
     * An `ArrayObject` is how API Platform makes an object encode as `{}` rather than `[]`, so it
     * is walked where it stands and stays one. Anything else here is a scalar or null.
     *
     * @template T
     *
     * @param T $value
     *
     * @return T
     */
    private static function floorInPlace(mixed $value): mixed
    {
        if ($value instanceof \ArrayObject) {
            foreach ($value as $key => $item) {
                $value[$key] = self::floor($item);
            }
        }

        return $value;
    }
}
