<?php

declare(strict_types=1);

namespace App\Mercure;

use App\ApiResource\Stage;
use App\Enum\ComputationName;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class TripUpdatePublisher implements TripUpdatePublisherInterface
{
    public function __construct(
        private HubInterface $hub,
        private StagePayloadMapper $stagePayloadMapper,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function publish(string $tripId, MercureEventType $type, array $data = []): void
    {
        $update = new Update(
            topics: [\sprintf('/trips/%s', $tripId)],
            data: json_encode(['type' => $type->value, 'data' => $data], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
            private: true,
        );

        $this->hub->publish($update);
    }

    public function publishValidationError(string $tripId, string $code, string $message): void
    {
        $this->publish($tripId, MercureEventType::VALIDATION_ERROR, [
            'code' => $code,
            'message' => $message,
        ]);
    }

    public function publishComputationError(string $tripId, string $computation, string $message, bool $retryable = true): void
    {
        $this->publish($tripId, MercureEventType::COMPUTATION_ERROR, [
            'computation' => $computation,
            'message' => $message,
            'retryable' => $retryable,
        ]);
    }

    /** @param array<string, string> $computationStatus */
    public function publishTripComplete(string $tripId, array $computationStatus): void
    {
        $this->publish($tripId, MercureEventType::TRIP_COMPLETE, [
            'computationStatus' => $computationStatus,
        ]);
    }

    public function publishComputationStepCompleted(
        string $tripId,
        ComputationName $step,
        int $completed,
        int $total,
        int $failed = 0,
    ): void {
        $this->publish($tripId, MercureEventType::COMPUTATION_STEP_COMPLETED, [
            'step' => $step->value,
            'category' => $step->category(),
            'completed' => $completed,
            'failed' => $failed,
            'total' => $total,
        ]);
    }

    /**
     * @param list<Stage>                                                                                                                                                                                                                     $stages
     * @param array{status: array<string, string>, aiOverview?: array{narrative: string, patterns: list<string>, recommendations: list<string>, crossStageAlerts: list<string>, model: string, promptVersion: int, generatedAt: string}|null} $summary
     */
    public function publishTripReady(string $tripId, array $stages, array $summary): void
    {
        $data = [
            'stages' => $this->stagePayloadMapper->toPayloadList($stages),
            'computationStatus' => $summary['status'] ?? [],
        ];

        if (\array_key_exists('aiOverview', $summary)) {
            $data['aiOverview'] = $summary['aiOverview'];
        }

        $this->publish($tripId, MercureEventType::TRIP_READY, $data);
    }

    public function publishStageUpdated(string $tripId, Stage $stage): void
    {
        $this->publish($tripId, MercureEventType::STAGE_UPDATED, [
            'stageIndex' => $stage->dayNumber - 1,
            'stage' => $this->stagePayloadMapper->toPayload($stage),
        ]);
    }
}
