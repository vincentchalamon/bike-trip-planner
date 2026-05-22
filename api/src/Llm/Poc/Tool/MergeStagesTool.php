<?php

declare(strict_types=1);

namespace App\Llm\Poc\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('merge_stages', 'Merge two consecutive stages into a single longer stage. Use when the rider asks to combine, merge, or join stages.')]
final class MergeStagesTool
{
    /**
     * @param int $firstStage  The 1-based index of the first stage to merge
     * @param int $secondStage The 1-based index of the second stage to merge (must be consecutive with the first)
     */
    public function __invoke(int $firstStage, int $secondStage): string
    {
        return \sprintf('Stages %d and %d merged.', $firstStage, $secondStage);
    }
}
