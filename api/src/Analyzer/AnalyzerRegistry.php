<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class AnalyzerRegistry implements AnalyzerRegistryInterface
{
    /** @var list<StageAnalyzerInterface> */
    private array $analyzers;

    /**
     * @param iterable<StageAnalyzerInterface> $analyzers
     */
    public function __construct(
        #[AutowireIterator('app.stage_analyzer')]
        iterable $analyzers,
    ) {
        // Manual sort: our convention is lower number = higher priority (runs first).
        // Symfony's AutowireIterator defaultPriorityMethod uses the opposite direction
        // (higher number = first), so native ordering would invert our intent.
        $unsorted = iterator_to_array($analyzers, false);
        usort($unsorted, static fn (StageAnalyzerInterface $a, StageAnalyzerInterface $b): int => $a::getPriority() <=> $b::getPriority());
        $this->analyzers = $unsorted;
    }

    /**
     * Runs all analyzers on a stage and returns all generated alerts.
     *
     * @param array<string, mixed> $context
     *
     * @return list<Alert>
     */
    public function analyze(Stage $stage, array $context = []): array
    {
        $alerts = [];

        foreach ($this->analyzers as $analyzer) {
            foreach ($analyzer->analyze($stage, $context) as $alert) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }
}
