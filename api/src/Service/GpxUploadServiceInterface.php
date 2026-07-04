<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\Entity\User;

/**
 * GPX upload business logic, behind an interface so the HTTP controller can be
 * unit-tested (the concrete service is `final readonly` and cannot be doubled).
 */
interface GpxUploadServiceInterface
{
    /**
     * @return list<Coordinate>
     *
     * @throws \RuntimeException When GPX content is invalid
     */
    public function parseGpx(string $content): array;

    public function extractTitle(string $content): ?string;

    /**
     * @param list<Coordinate> $points
     *
     * @return array{tripId: string, computationStatus: array<string, string>, totalDistance: float, totalElevation: int, totalElevationLoss: int, status: string, stages: list<array<string, mixed>>}
     */
    public function createTrip(
        array $points,
        ?string $title,
        TripRequest $tripRequest,
        string $locale,
        User $user,
    ): array;
}
