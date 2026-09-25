<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\WriteAcknowledgement;
use App\ApiResource\StagePoiWaypointRequest;
use App\State\StagePoiWaypointProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Routes a day through a place, and says the version has not moved.
 *
 * Wraps the HTTP processor untouched. What changes is the answer: the HTTP one returns the whole
 * stage, which is a lot of JSON saying nothing about what was just asked for, and the reroute has
 * not happened yet anyway — it is a message on a bus.
 *
 * The acknowledgement carries no version on purpose, and says why in words: this write does not
 * move the trip's structural version, so whatever version the agent already holds is still
 * current. Leaving it silent would send it off to re-read the trip for nothing.
 *
 * @implements ProcessorInterface<StagePoiWaypointRequest, WriteAcknowledgement>
 */
final readonly class McpAddWaypointProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<StagePoiWaypointRequest, mixed> $waypoints
     */
    public function __construct(
        #[Autowire(service: StagePoiWaypointProcessor::class)]
        private ProcessorInterface $waypoints,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WriteAcknowledgement
    {
        $this->waypoints->process($data, $operation, $uriVariables, $context);

        return new WriteAcknowledgement(
            result: 'The day is being rerouted through that place. Its distance and climbing will change once the new route comes back.',
            nextAction: 'Call `get_stage` for this day in a little while to see the new route. The trip version has not moved, so the one you already have is still good for the next edit.',
        );
    }
}
