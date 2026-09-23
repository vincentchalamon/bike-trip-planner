<?php

declare(strict_types=1);

namespace App\Metadata;

use ApiPlatform\Metadata\Operations;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response;
use App\State\TripLockProcessor;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Documents the 423 the trip lock answers with.
 *
 * The status existed for a long time and was published nowhere: eleven operations could return
 * it and the exported document mentioned it zero times, while the mobile client had been
 * mapping it to a "locked" failure anyway. A client was coding against a status the contract
 * denied.
 *
 * Driven by the same operation flag {@see TripLockProcessor} enforces, for the reason
 * {@see IfMatchMetadataFactory} gives: one declaration, applied and advertised from the same
 * place, cannot drift from itself.
 */
#[AsDecorator(decorates: 'api_platform.metadata.resource.metadata_collection_factory')]
final readonly class TripLockMetadataFactory implements ResourceMetadataCollectionFactoryInterface
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
                if (true !== ($operation->getExtraProperties()[TripLockProcessor::EXTRA_PROPERTY] ?? false)) {
                    continue;
                }

                $openapi = $operation->getOpenapi();
                if (false === $openapi) {
                    continue;
                }

                $openapi = $openapi instanceof OpenApiOperation ? $openapi : new OpenApiOperation();
                $operations->add($name, $operation->withOpenapi(
                    // Union, so an operation that describes its own 423 keeps its wording.
                    $openapi->withResponses(($openapi->getResponses() ?? []) + [
                        423 => new Response(description: 'This trip has started; its contents can no longer be rewritten. The refusal is permanent — a start date never moves back into the future.'),
                    ]),
                ));
            }
        }

        return $collection;
    }
}
