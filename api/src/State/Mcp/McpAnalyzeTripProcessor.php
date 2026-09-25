<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\WriteAcknowledgement;
use App\ApiResource\TripRequest;
use App\State\AnalyzeTripProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Starts the enrichment pipeline and hands the loop back to the agent.
 *
 * Fifteen enrichments are dispatched to workers and none of them has finished when this
 * returns. There is no waiting here and there will not be: `ComputationTracker` is a Redis
 * store with no pub/sub and no blocking primitive, so waiting means polling inside the request
 * — a worker pinned for up to 45 seconds, behind which the PWA's own requests queue, on a call
 * most MCP clients cut at 30 seconds anyway.
 *
 * So the answer says what to do instead, and the agent's own loop is the progress bar. It is
 * better at it than a `sleep()` would be: it can say what it is waiting for, and do something
 * else in between. That is ADR-057 transposed from the browser to the agent.
 *
 * @implements ProcessorInterface<TripRequest, WriteAcknowledgement>
 */
final readonly class McpAnalyzeTripProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TripRequest, mixed> $analysis
     */
    public function __construct(
        #[Autowire(service: AnalyzeTripProcessor::class)]
        private ProcessorInterface $analysis,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WriteAcknowledgement
    {
        $this->analysis->process($data, $operation, $uriVariables, $context);

        return new WriteAcknowledgement(
            result: 'The enrichment pipeline has been started: points of interest, accommodation, weather, terrain, resupply and the rest are being recomputed for every day of the trip.',
            nextAction: 'Nothing is ready yet. Call `get_trip` in a little while — usually under a minute — and read `categoryStatus` to see which families have finished.',
        );
    }
}
