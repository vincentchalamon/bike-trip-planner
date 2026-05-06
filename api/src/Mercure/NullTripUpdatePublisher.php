<?php

declare(strict_types=1);

namespace App\Mercure;

use App\ApiResource\Stage;
use App\Enum\ComputationName;

/**
 * No-op publisher used in test environment where no Mercure hub is available.
 */
final readonly class NullTripUpdatePublisher implements TripUpdatePublisherInterface
{
    /** @param array<string, mixed> $data */
    public function publish(string $tripId, MercureEventType $type, array $data = []): void
    {
    }

    public function publishValidationError(string $tripId, string $code, string $message): void
    {
    }

    public function publishComputationError(string $tripId, string $computation, string $message, bool $retryable = true): void
    {
    }

    /** @param array<string, string> $computationStatus */
    public function publishTripComplete(string $tripId, array $computationStatus): void
    {
    }

    public function publishComputationStepCompleted(
        string $tripId,
        ComputationName $step,
        int $completed,
        int $total,
        int $failed = 0,
    ): void {
    }

    /**
     * @param list<Stage>                                                $stages
     * @param array{status: array<string, string>, aiOverview?: ?string} $summary
     */
    public function publishTripReady(string $tripId, array $stages, array $summary): void
    {
    }

    public function publishStageUpdated(string $tripId, Stage $stage): void
    {
    }
}
