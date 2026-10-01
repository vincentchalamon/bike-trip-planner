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
use App\State\Idempotency;
use App\State\PreconditionProcessor;
use App\State\TripCreation;
use App\State\TripLockProcessor;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Documents the headers and statuses an operation flag makes the server enforce.
 *
 * Driven by the same extra property the enforcing code reads ({@see PreconditionProcessor},
 * {@see TripLockProcessor}, {@see Idempotency}), so the spec cannot claim a rule the server does
 * not apply, nor omit one it does. Written once here rather than copied into each resource's
 * attributes: a hand-repeated `responses:` block is exactly the kind of thing that rots one
 * operation at a time. The 423 of the trip lock is the cautionary tale: eleven operations
 * could return it and the exported document mentioned it zero times, while the mobile client
 * was mapping it to a "locked" failure anyway.
 *
 * Applied to the resource metadata rather than to the exported document, so nothing has to
 * match an operation back to a path and an `operationId` — the two are derived differently and
 * do not line up.
 */
#[AsDecorator(decorates: 'api_platform.metadata.resource.metadata_collection_factory')]
final readonly class OperationContractMetadataFactory implements ResourceMetadataCollectionFactoryInterface
{
    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $decorated,
    ) {
    }

    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $collection = $this->decorated->create($resourceClass);
        $contracts = $this->contracts();

        foreach ($collection as $resource) {
            $operations = $resource->getOperations();
            if (!$operations instanceof Operations) {
                continue;
            }

            foreach ($operations as $name => $operation) {
                $openapi = $operation->getOpenapi();
                if (false === $openapi) {
                    continue;
                }

                $flags = $operation->getExtraProperties();
                $applied = false;
                $openapi = $openapi instanceof OpenApiOperation ? $openapi : new OpenApiOperation();

                foreach ($contracts as $flag => [$header, $responses]) {
                    if (true !== ($flags[$flag] ?? false)) {
                        continue;
                    }

                    if ($header instanceof Parameter) {
                        $openapi = $openapi->withParameters([...($openapi->getParameters() ?? []), $header]);
                    }

                    // Union, so an operation that describes one of these statuses itself keeps
                    // its own wording.
                    $openapi = $openapi->withResponses(($openapi->getResponses() ?? []) + $responses);
                    $applied = true;
                }

                if ($applied) {
                    $operations->add($name, $operation->withOpenapi($openapi));
                }
            }
        }

        return $collection;
    }

    /**
     * What each flag publishes, in the order it is applied.
     *
     * @return array<string, array{Parameter|null, array<int, Response>}>
     */
    private function contracts(): array
    {
        return [
            PreconditionProcessor::EXTRA_PROPERTY => [
                new Parameter(
                    name: IfMatch::HEADER,
                    in: 'header',
                    description: 'The trip version this edit was computed against, quoted, as served by the ETag of the response it came from — for example `"7"`. `*` accepts whatever the current version is.',
                    required: true,
                    schema: ['type' => 'string', 'pattern' => IfMatch::PATTERN],
                ),
                [
                    412 => new Response(description: 'The trip has changed since the version you sent; reload it and reapply your change.'),
                    428 => new Response(description: \sprintf('This operation requires an "%s" header carrying the trip version, taken from the ETag of the response that served it.', IfMatch::HEADER)),
                ],
            ],
            TripLockProcessor::EXTRA_PROPERTY => [
                null,
                [
                    423 => new Response(description: 'This trip has started; its contents can no longer be rewritten. The refusal is permanent — a start date never moves back into the future.'),
                ],
            ],
            TripCreation::REQUIRES_IDEMPOTENCY_KEY => [
                new Parameter(
                    name: Idempotency::HEADER,
                    in: 'header',
                    description: 'An opaque string identifying this creation, minted once per user intent and sent again unchanged on every retry of it. Replaying it returns the trip the first call created instead of making another. Minting a fresh one per HTTP attempt protects nothing.',
                    required: true,
                    schema: ['type' => 'string', 'pattern' => Idempotency::KEY_PATTERN],
                ),
                [
                    400 => new Response(description: \sprintf('The "%s" header is missing or malformed.', Idempotency::HEADER)),
                    409 => new Response(description: \sprintf('This "%s" was already used for a different request body.', Idempotency::HEADER)),
                ],
            ],
        ];
    }
}
