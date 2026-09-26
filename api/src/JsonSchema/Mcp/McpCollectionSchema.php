<?php

declare(strict_types=1);

namespace App\JsonSchema\Mcp;

use ApiPlatform\JsonSchema\Schema;
use ApiPlatform\JsonSchema\SchemaFactoryInterface;
use ApiPlatform\Metadata\Operation;

/**
 * The output schema of a tool that answers a list, rather than the schema of one of its items.
 *
 * `tools/list` builds a tool's `outputSchema` from the class its operation declares as output,
 * and builds it as a single item — the Loader never asks for a collection. Two of the thirteen
 * tools answer a Hydra collection, and both therefore published a promise their answer breaks:
 *
 *  - `search_places` published the schema of one `GeocodeResult`, `@id` typed as a string. The
 *    answer is a collection whose `@id` is null, because an MCP operation has no routed IRI
 *    ({@see \ApiPlatform\Mcp\Routing\IriConverter}), so a client that validates refused it
 *    outright: `data/@id must be string`;
 *  - `list_trips` published the schema of one `TripListItem`. Nothing refused it, which is
 *    worse: the answer is a collection, none of the announced properties is at the top level of
 *    it, and no `required` and no closed object meant the mismatch validated vacuously.
 *
 * So the envelope is built here, around whatever the item schema turns out to be: `member`
 * carrying the items, `totalItems` beside it, and nothing else promised. The three JSON-LD keys
 * travel in the answer and are deliberately NOT declared — the identifier among them is null on
 * this transport, and a schema that named it would be promising the one thing that is not true.
 *
 * (Those keys are not written out above on purpose: a php-cs-fixer rule rewrites what looks like
 * a PHPDoc tag inside a docblock, and it turned one of them into `@var` when they were.)
 *
 * Asking the decorated factory for a collection directly was measured first, and it only works
 * for a resource class: `GeocodeResult` gives the Hydra collection schema, `TripListItem` — a
 * plain DTO — gives an empty object. One shape for both tools is worth more than reusing a
 * builder for half of them.
 *
 * The item schema is embedded whole, one level down, and that is only safe because the factory
 * decorated here is {@see \ApiPlatform\Mcp\JsonSchema\SchemaFactory}, whose entire job is to
 * flatten — "no $ref, no allOf, no definitions". Measured across the thirteen tools, nested
 * ones included: not one carries a reference, and `getArrayCopy()` returns the same document
 * with or without definitions. Were it otherwise, a `#/definitions/...` pointer would travel
 * down into `member.items` and resolve against nothing — the very shape of refusal this class
 * exists to end. No hoisting is written for a case the layer below rules out; what stands
 * instead is
 * {@see \App\Tests\Functional\McpOutputSchemaTest::noPublishedSchemaLeavesAReferenceToResolve},
 * so the day that layer changes, it is a red test rather than a client's refusal.
 *
 * A tool opts in by declaring `mcp_collection` in its extra properties. Declared rather than
 * guessed: whether a provider answers one record or a page of them is not visible in the
 * metadata. What keeps the declaration honest is
 * {@see \App\Tests\Functional\McpOutputSchemaTest}, which validates each answer against the
 * schema its tool publishes and, separately, asserts the envelope — because a schema can
 * describe an answer and still be the wrong schema.
 */
final readonly class McpCollectionSchema implements SchemaFactoryInterface
{
    public const string EXTRA_PROPERTY = 'mcp_collection';

    public function __construct(private SchemaFactoryInterface $decorated)
    {
    }

    public function buildSchema(string $className, string $format = 'json', string $type = Schema::TYPE_OUTPUT, ?Operation $operation = null, ?Schema $schema = null, ?array $serializerContext = null, bool $forceCollection = false): Schema
    {
        $item = $this->decorated->buildSchema($className, $format, $type, $operation, $schema, $serializerContext, $forceCollection);

        if (Schema::TYPE_OUTPUT !== $type || true !== ($operation?->getExtraProperties()[self::EXTRA_PROPERTY] ?? null)) {
            return $item;
        }

        $collection = new Schema(Schema::VERSION_JSON_SCHEMA);
        unset($collection['$schema']);

        $collection['type'] = 'object';
        $collection['properties'] = [
            'totalItems' => ['type' => 'integer', 'description' => 'How many records match in total, across every page.'],
            'member' => ['type' => 'array', 'items' => $item->getArrayCopy(), 'description' => 'The records on this page.'],
        ];
        $collection['required'] = ['member'];

        return $collection;
    }
}
