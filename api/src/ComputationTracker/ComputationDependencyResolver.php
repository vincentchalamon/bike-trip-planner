<?php

declare(strict_types=1);

namespace App\ComputationTracker;

use App\ApiResource\TripRequest;
use App\Enum\ComputationName;

final class ComputationDependencyResolver
{
    /** @var array<string, array<ComputationName>> */
    private const array PARAMETER_DEPENDENCIES = [
        'sourceUrl' => [ComputationName::ROUTE],            // cascade everything
        'endDate' => [ComputationName::STAGES],              // cascade subtree
        'fatigueFactor' => [ComputationName::STAGES],        // cascade subtree
        'elevationPenalty' => [ComputationName::STAGES],     // cascade subtree
        'maxDistancePerDay' => [ComputationName::STAGES],    // cascade subtree
        'startDate' => [ComputationName::WEATHER, ComputationName::CALENDAR],
        'ebikeMode' => [ComputationName::TERRAIN],           // re-analyze only
    ];

    /**
     * Returns root computations to re-dispatch.
     * De-duplicates: if sourceUrl changes, Route alone cascades everything.
     *
     * @return list<ComputationName>
     */
    public function resolve(TripRequest $old, TripRequest $new): array
    {
        $computations = [];

        foreach (self::PARAMETER_DEPENDENCIES as $parameter => $deps) {
            if ($this->parameterChanged($old, $new, $parameter)) {
                foreach ($deps as $dep) {
                    $computations[$dep->value] = $dep;
                }
            }
        }

        // If ROUTE is triggered, it cascades everything — no need to add others separately
        if (isset($computations[ComputationName::ROUTE->value])) {
            return [ComputationName::ROUTE];
        }

        // If STAGES is triggered, it cascades its subtree — remove weather/calendar if also triggered by stages
        return array_values($computations);
    }

    private function parameterChanged(TripRequest $old, TripRequest $new, string $parameter): bool
    {
        return match ($parameter) {
            'sourceUrl' => $old->sourceUrl !== $new->sourceUrl,
            'startDate' => $old->startDate?->format('Y-m-d') !== $new->startDate?->format('Y-m-d'),
            'endDate' => $old->endDate?->format('Y-m-d') !== $new->endDate?->format('Y-m-d'),
            'fatigueFactor' => $old->fatigueFactor !== $new->fatigueFactor,
            'elevationPenalty' => $old->elevationPenalty !== $new->elevationPenalty,
            'maxDistancePerDay' => $old->maxDistancePerDay !== $new->maxDistancePerDay,
            'ebikeMode' => $old->ebikeMode !== $new->ebikeMode,
            default => false,
        };
    }
}
