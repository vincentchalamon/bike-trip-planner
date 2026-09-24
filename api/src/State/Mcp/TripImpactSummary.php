<?php

declare(strict_types=1);

namespace App\State\Mcp;

use App\Entity\TripShare;
use App\ApiResource\Mcp\TripImpact;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripShareRepositoryInterface;

/**
 * What a destructive tool is about to act on, in the terms a person would recognise it by.
 *
 * Read from the repositories by identifier rather than projected from whatever the provider
 * happened to return: the tools that need this return four different shapes — a trip, a share
 * link, a void — and a summary that depended on which one would be a summary that breaks the
 * day a tool is added. The trip has just been loaded and authorized on the same request, so
 * this is a warm read.
 */
final readonly class TripImpactSummary
{
    public function __construct(
        private TripRequestRepositoryInterface $trips,
        private TripShareRepositoryInterface $shares,
    ) {
    }

    public function for(string $tripId): TripImpact
    {
        $request = $this->trips->getRequest($tripId);

        return new TripImpact(
            tripId: $tripId,
            // The title comes from Komoot, Strava or the user. It is quoted back into an
            // answer a model will read, so it goes through the same sanitiser as every other
            // third-party label (ADR-080 defers the injection posture to 3C; this is only
            // about control characters and length).
            title: ThirdPartyText::clean($request?->title),
            stageCount: \count($this->trips->getStages($tripId) ?? []),
            startDate: $request?->startDate,
            endDate: $request?->endDate,
            hasActiveShareLink: $this->shares->findActiveByTrip($tripId) instanceof TripShare,
        );
    }
}
