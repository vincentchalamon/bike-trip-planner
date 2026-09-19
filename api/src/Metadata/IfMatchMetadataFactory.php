<?php

declare(strict_types=1);

namespace App\Metadata;

use ApiPlatform\Metadata\Operations;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response;
use App\Concurrency\IfMatch;
use App\State\PreconditionProcessor;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Documents the `If-Match` precondition on the operations that require it.
 *
 * Driven by the same operation flag {@see PreconditionProcessor} enforces, so the spec cannot
 * claim a precondition the server does not check, nor omit one it does. Written once here
 * rather than copied into ten sets of resource attributes: a hand-repeated `responses:` block
 * is exactly the kind of thing that rots one operation at a time.
 *
 * Applied to the resource metadata rather than to the exported document, so nothing has to
 * match an operation back to a path and an `operationId` — the two are derived differently
 * and do not line up.
 */
#[AsDecorator(decorates: 'api_platform.metadata.resource.metadata_collection_factory')]
final readonly class IfMatchMetadataFactory implements ResourceMetadataCollectionFactoryInterface
{
    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $decorated,
    ) {
    }

    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $collection = $this->decorated->create($resourceClass);

        foreach ($collection as $resource) {
            $operations = $resource->getOperations();
            if (!$operations instanceof Operations) {
                continue;
            }

            foreach ($operations as $name => $operation) {
                if (true !== ($operation->getExtraProperties()[PreconditionProcessor::EXTRA_PROPERTY] ?? false)) {
                    continue;
                }

                $openapi = $operation->getOpenapi();
                if (false === $openapi) {
                    continue;
                }

                $openapi = $openapi instanceof OpenApiOperation ? $openapi : new OpenApiOperation();
                $operations->add($name, $operation->withOpenapi(
                    $openapi
                        ->withParameters([...($openapi->getParameters() ?? []), $this->header()])
                        ->withResponses(($openapi->getResponses() ?? []) + [
                            412 => new Response(description: 'The trip has changed since the version you sent; reload it and reapply your change.'),
                            428 => new Response(description: \sprintf('This operation requires an "%s" header carrying the trip version, taken from the ETag of the response that served it.', IfMatch::HEADER)),
                        ]),
                ));
            }
        }

        return $collection;
    }

    private function header(): Parameter
    {
        return new Parameter(
            name: IfMatch::HEADER,
            in: 'header',
            description: 'The trip version this edit was computed against, quoted, as served by the ETag of the response it came from — for example `"7"`. `*` accepts whatever the current version is.',
            required: true,
            schema: ['type' => 'string', 'pattern' => '^(\*|"\d+")$'],
        );
    }
}
