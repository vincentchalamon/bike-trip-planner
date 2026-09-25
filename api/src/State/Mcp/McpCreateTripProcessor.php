<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\TripCreated;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\State\TripCreateProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates the trip and hands the loop back to the agent.
 *
 * Wraps the HTTP processor untouched: the rate limit, the idempotency bookkeeping and the order
 * the two flushes happen in are the same ones, and re-implementing any part of that for a second
 * transport is how two callers end up with two different rules. What changes is the answer.
 * `Trip` says `{id, computationStatus, isLocked}`, which is the right answer to a 202 with a
 * `Location` header — neither of which exists here. A model needs to be told what to do next.
 *
 * @implements ProcessorInterface<TripRequest, TripCreated>
 */
final readonly class McpCreateTripProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TripRequest, Trip> $creations
     */
    public function __construct(
        #[Autowire(service: TripCreateProcessor::class)]
        private ProcessorInterface $creations,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TripCreated
    {
        $trip = $this->creations->process($data, $operation, $uriVariables, $context);

        return new TripCreated(
            id: $trip->id,
            result: 'The trip has been created. Its route is being fetched and its days worked out.',
            nextAction: \sprintf('Call `get_trip` with id %s. The days appear once the route and the pacing are done, usually 10 to 40 seconds; until then the answer says `partial: true` and carries no days. Report the wait to the user rather than calling in a tight loop.', $trip->id),
        );
    }
}
