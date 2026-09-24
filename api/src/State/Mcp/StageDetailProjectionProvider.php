<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mcp\StageDetail;
use App\State\StageDetailProvider;

/**
 * Projects one stage into what `get_stage` answers with: everything but the coordinate trail.
 *
 * Same arrangement as {@see TripDigestProvider} and for the same reason — the HTTP provider
 * decides authorization, absence and rendering, and a second implementation of any of those
 * would be a second place to get them wrong. Only the shape of the answer differs.
 *
 * @implements ProviderInterface<StageDetail>
 */
final readonly class StageDetailProjectionProvider implements ProviderInterface
{
    public function __construct(private StageDetailProvider $stage)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StageDetail
    {
        $stage = $this->stage->provide($operation, $uriVariables, $context);

        return new StageDetail(
            id: $stage->id,
            dayNumber: $stage->dayNumber,
            distance: $stage->distance,
            elevation: $stage->elevation,
            elevationLoss: $stage->elevationLoss,
            startPoint: $stage->startPoint,
            endPoint: $stage->endPoint,
            label: ThirdPartyText::clean($stage->label),
            isRestDay: $stage->isRestDay,
            weather: $stage->weather,
            alerts: array_values($stage->alerts),
            resupply: $stage->resupply,
            accommodations: array_values($stage->accommodations),
            selectedAccommodation: $stage->selectedAccommodation,
            events: array_values($stage->events),
        );
    }
}
