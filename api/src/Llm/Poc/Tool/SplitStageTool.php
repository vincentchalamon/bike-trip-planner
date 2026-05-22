<?php

declare(strict_types=1);

namespace App\Llm\Poc\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('split_stage', 'Split the targeted stage of the trip into two stages of roughly equal distance. Use when the rider asks to cut, divide, or split a stage.')]
final class SplitStageTool
{
    /**
     * @param int $stage The 1-based index of the stage to split
     */
    public function __invoke(int $stage): string
    {
        return \sprintf('Stage %d split into two.', $stage);
    }
}
