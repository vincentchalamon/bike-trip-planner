<?php

declare(strict_types=1);

namespace App\Llm\Poc\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('add_waypoint', 'Add a named geographic waypoint (city, village, point of interest) to the trip. Use when the rider asks to add, insert, or include a place by its proper name.')]
final class AddWaypointTool
{
    /**
     * @param string   $name  Name of the place to add (e.g. "Tournus", "Cluny")
     * @param int|null $stage Optional 1-based stage index where the waypoint should land; null means auto-place
     */
    public function __invoke(string $name, ?int $stage = null): string
    {
        return \sprintf('Waypoint "%s" added%s.', $name, null === $stage ? '' : ' to stage '.$stage);
    }
}
