<?php

declare(strict_types=1);

namespace App\Llm\Poc\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('change_route', 'Recompute the entire route from scratch. Use when the rider asks to redo, recompute, or change the whole itinerary.')]
final class ChangeRouteTool
{
    public function __invoke(): string
    {
        return 'Route recomputed.';
    }
}
