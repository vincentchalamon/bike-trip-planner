<?php

declare(strict_types=1);

namespace App\Llm\Poc\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('adjust_distance', 'Adjust the target distance in kilometers for a specific stage. Use when the rider asks to make a stage longer, shorter, or set a specific distance.')]
final class AdjustDistanceTool
{
    /**
     * @param int   $stage The 1-based stage index
     * @param float $km    Target distance in kilometers (must be positive)
     */
    public function __invoke(int $stage, float $km): string
    {
        return \sprintf('Stage %d distance set to %.1f km.', $stage, $km);
    }
}
