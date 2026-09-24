<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mcp\CategoryStatus;
use App\ApiResource\Mcp\StageDigest;
use App\ApiResource\Mcp\TripDigest;
use App\Concurrency\TripVersionEtag;
use App\State\TripDetailProvider;
use Symfony\Component\HttpFoundation\Request;

/**
 * Projects the trip detail into the shape `get_trip` answers with.
 *
 * Reuses {@see TripDetailProvider} whole rather than querying again: the authorization, the
 * not-found behaviour, the reader's locale, the alert rendering and the category statuses are
 * all decided there, and a second implementation of any of them would be a second place to get
 * them wrong.
 *
 * @implements ProviderInterface<TripDigest>
 */
final readonly class TripDigestProvider implements ProviderInterface
{
    public function __construct(private TripDetailProvider $detail)
    {
    }

    /**
     * @param array{id?: string}   $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripDigest
    {
        $detail = $this->detail->provide($operation, $uriVariables, $context);

        $stages = array_values(array_map($this->stage(...), $detail->stages));

        return new TripDigest(
            id: $detail->id,
            version: $this->version($context),
            title: ThirdPartyText::clean($detail->title),
            sourceUrl: $detail->sourceUrl,
            startDate: $detail->startDate,
            endDate: $detail->endDate,
            status: $detail->status,
            // ADR-043: the status is `draft` until the pacing stages are persisted. That is
            // exactly the question — an empty list means "not yet", not "none".
            partial: 'ready' !== $detail->status,
            isLocked: $detail->isLocked,
            outOfZone: $detail->outOfZone,
            fatigueFactor: $detail->fatigueFactor,
            elevationPenalty: $detail->elevationPenalty,
            maxDistancePerDay: $detail->maxDistancePerDay,
            averageSpeed: $detail->averageSpeed,
            ebikeMode: $detail->ebikeMode,
            departureHour: $detail->departureHour,
            enabledAccommodationTypes: array_values($detail->enabledAccommodationTypes),
            categoryStatus: array_values(array_map(
                static fn (string $category, string $status): CategoryStatus => new CategoryStatus($category, $status),
                array_keys($detail->categoryStatus),
                array_values($detail->categoryStatus),
            )),
            stageCount: \count($stages),
            totalDistance: array_sum(array_map(
                static fn (StageDigest $stage): float => $stage->isRestDay ? 0.0 : $stage->distance,
                $stages,
            )),
            stages: $stages,
        );
    }

    /**
     * The version {@see TripDetailProvider} already observed.
     *
     * It stamps it on the request so the HTTP response can advertise it as an `ETag`. The
     * number therefore already travels on this transport too — a tool call is a POST /mcp, so
     * there is a request to stamp — it simply had nowhere to land, since a `tools/call` answer
     * carries no per-call headers. Reading it back here is cheaper and safer than loading the
     * trip a second time, which would report whatever version won a race in between.
     *
     * @param array<string, mixed> $context
     */
    private function version(array $context): int
    {
        $request = $context['request'] ?? null;

        \assert($request instanceof Request, 'The digest is built from a request-bound read; there is nothing to pin without one.');

        $version = $request->attributes->get(TripVersionEtag::ATTRIBUTE);

        \assert(\is_int($version), 'TripDetailProvider stamps the version it read; its absence means that contract changed.');

        return $version;
    }

    /**
     * @param array<string, mixed> $stage a serialized stage, as TripDetail carries them
     */
    private function stage(array $stage): StageDigest
    {
        $alerts = \is_array($stage['alerts'] ?? null) ? $stage['alerts'] : [];

        $critical = [];
        foreach ($alerts as $alert) {
            if (\is_array($alert) && 'critical' === ($alert['type'] ?? null) && \is_string($alert['message'] ?? null)) {
                $critical[] = ThirdPartyText::clean($alert['message']) ?? '';
            }
        }

        $accommodation = $stage['selectedAccommodation'] ?? null;

        return new StageDigest(
            stageId: \is_string($stage['stageId'] ?? null) ? $stage['stageId'] : '',
            dayNumber: \is_int($stage['dayNumber'] ?? null) ? $stage['dayNumber'] : 0,
            distance: \is_float($stage['distance'] ?? null) ? $stage['distance'] : 0.0,
            elevation: \is_float($stage['elevation'] ?? null) ? $stage['elevation'] : 0.0,
            startLabel: ThirdPartyText::clean(\is_string($stage['startLabel'] ?? null) ? $stage['startLabel'] : null),
            endLabel: ThirdPartyText::clean(\is_string($stage['endLabel'] ?? null) ? $stage['endLabel'] : null),
            isRestDay: (bool) ($stage['isRestDay'] ?? false),
            accommodation: ThirdPartyText::clean(
                \is_array($accommodation) && \is_string($accommodation['name'] ?? null) ? $accommodation['name'] : null,
            ),
            alertCount: \count($alerts),
            criticalAlerts: array_values(array_filter($critical)),
        );
    }
}
