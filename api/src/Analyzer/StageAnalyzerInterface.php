<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.stage_analyzer')]
interface StageAnalyzerInterface
{
    /**
     * Analyzes a stage and returns alerts.
     *
     * @param array<string, mixed> $context Additional data (nextStage, tripDays, startDate, osmPois, weatherData...)
     *
     * @return list<Alert>
     */
    public function analyze(Stage $stage, array $context = []): array;

    /**
     * Lower value = higher priority (runs first).
     * Convention: 5=continuity, 10=critical terrain, 20=warning terrain, 100=nudges.
     */
    public static function getPriority(): int;
}
