<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\WriteAcknowledgement;
use App\ApiResource\TripRequest;
use App\State\TripDeleteProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes a trip and says what went with it.
 *
 * The HTTP processor returns nothing, which is what `204 No Content` means. On this transport
 * the absence of a body is not an answer: a model that gets `null` back cannot tell a deletion
 * from a call that did nothing, and the natural next move — calling it again — is the one thing
 * it must not do.
 *
 * @implements ProcessorInterface<TripRequest, WriteAcknowledgement>
 */
final readonly class McpDeleteTripProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TripRequest, mixed> $trips
     */
    public function __construct(
        #[Autowire(service: TripDeleteProcessor::class)]
        private ProcessorInterface $trips,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WriteAcknowledgement
    {
        $this->trips->process($data, $operation, $uriVariables, $context);

        return new WriteAcknowledgement(
            result: 'The trip is deleted, with its days, its computations and any share link that pointed at it. This cannot be undone.',
        );
    }
}
