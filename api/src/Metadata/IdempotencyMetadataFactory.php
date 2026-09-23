<?php

declare(strict_types=1);

namespace App\Metadata;

use ApiPlatform\Metadata\Operations;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response;
use App\State\Idempotency;
use App\State\TripCreation;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Documents the `Idempotency-Key` the creations require.
 *
 * Same arrangement as {@see IfMatchMetadataFactory} and {@see TripLockMetadataFactory}: the flag
 * that applies the rule is the flag that publishes it, so the document cannot promise a header
 * the server ignores, nor stay silent about one it demands.
 */
#[AsDecorator(decorates: 'api_platform.metadata.resource.metadata_collection_factory')]
final readonly class IdempotencyMetadataFactory implements ResourceMetadataCollectionFactoryInterface
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
                if (true !== ($operation->getExtraProperties()[TripCreation::REQUIRES_IDEMPOTENCY_KEY] ?? false)) {
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
                            400 => new Response(description: \sprintf('The "%s" header is missing or malformed.', Idempotency::HEADER)),
                            409 => new Response(description: \sprintf('This "%s" was already used for a different request body.', Idempotency::HEADER)),
                        ]),
                ));
            }
        }

        return $collection;
    }

    private function header(): Parameter
    {
        return new Parameter(
            name: Idempotency::HEADER,
            in: 'header',
            description: 'An opaque string identifying this creation, minted once per user intent and sent again unchanged on every retry of it. Replaying it returns the trip the first call created instead of making another. Minting a fresh one per HTTP attempt protects nothing.',
            required: true,
            schema: ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{16,255}$'],
        );
    }
}
