<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * What a creation answers, which is an identifier and an instruction.
 *
 * Nothing about the trip is computed yet when this is built, and nothing here pretends
 * otherwise. Waiting is not an option worth having: `ComputationTracker` is a Redis store with
 * no pub/sub and no blocking primitive, so waiting means polling inside the request — a worker
 * pinned for up to 45 seconds, behind which the browser clients queue, on a call most MCP
 * clients cut at 30 seconds anyway.
 *
 * So the agent's own loop is the progress bar, which is ADR-057 transposed from the browser to
 * the agent, and it is better at it: it can say what it is waiting for and do something else in
 * between.
 *
 * There is deliberately no version here. A trip with no days has nothing to edit, and the
 * version will have moved by the time the days arrive — advertising `1` would invite an edit
 * that is refused.
 */
final readonly class TripCreated
{
    public function __construct(
        #[ApiProperty(description: 'Identifier of the new trip. Pass it to `get_trip`.')]
        public string $id,
        #[ApiProperty(description: 'What was done. Report it to the user.')]
        public string $result,
        #[ApiProperty(description: 'What to do next.')]
        public string $nextAction,
    ) {
    }
}
