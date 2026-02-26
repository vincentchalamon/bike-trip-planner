<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;

/**
 * Runs all registered stage analyzers and aggregates their alerts.
 */
interface AnalyzerRegistryInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return list<Alert>
     */
    public function analyze(Stage $stage, array $context = []): array;
}
