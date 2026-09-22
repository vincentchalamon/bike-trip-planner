<?php

declare(strict_types=1);

namespace App\Mercure;

use App\ApiResource\Stage;
use App\Enum\ComputationName;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class TripUpdatePublisher implements TripUpdatePublisherInterface
{
    public function __construct(
        private HubInterface $hub,
        private StagePayloadMapper $stagePayloadMapper,
        private CurrentCorrelationIdProvider $correlationIdProvider,
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function publish(string $tripId, MercureEventType $type, array $data = []): void
    {
        $payload = ['type' => $type->value, 'data' => $data];

        // The trip's structural version, at the envelope root next to the correlation id.
        // Without it a regeneration performed by a worker — which bumps the version without
        // any HTTP response to carry a fresh ETag — would leave the client pinned to a
        // version that no longer exists, and every edit it attempted afterwards would be
        // refused with 412 until it reloaded. Mercure is the invalidation channel, so the
        // invalidation token belongs on it.
        $version = $this->tripStateManager->getVersion($tripId);
        if (null !== $version) {
            $payload['version'] = $version;
        }

        $correlationId = $this->correlationIdProvider->current();
        if (null !== $correlationId) {
            $payload['correlationId'] = $correlationId;
        }

        $update = new Update(
            topics: [\sprintf('/trips/%s', $tripId)],
            data: json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
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

    public function publishComputationsSuperseded(string $tripId, array $computations): void
    {
        $this->publish($tripId, MercureEventType::COMPUTATIONS_SUPERSEDED, [
            'computations' => array_map(static fn (ComputationName $c): string => $c->value, $computations),
            // The categories too, because that is the granularity both clients render a
            // per-block spinner at; deriving it client-side would duplicate
            // ComputationName::category() in TypeScript.
            'categories' => array_values(array_unique(
                array_map(static fn (ComputationName $c): string => $c->category(), $computations),
            )),
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
     * @param list<Stage>                          $stages
     * @param array{status: array<string, string>} $summary
     */
    public function publishTripReady(string $tripId, array $stages, array $summary): void
    {
        $data = [
            'stages' => $this->stagePayloadMapper->toPayloadList($stages, $this->tripStateManager->getLocale($tripId) ?? 'en'),
            'computationStatus' => $summary['status'] ?? [],
        ];

        $this->publish($tripId, MercureEventType::TRIP_READY, $data);
    }

    /**
     * Carries both the identity and the position of the stage.
     *
     * The identity is what the client matches on. The position is what tells it, when the
     * identity is unknown, whether this is a stage that was just appended (the trailing
     * stage a distance edit splits off, #840) or an event from a superseded generation to
     * be dropped — an unknown identifier alone cannot distinguish the two.
     */
    public function publishStageUpdated(string $tripId, Stage $stage, int $position): void
    {
        $this->publish($tripId, MercureEventType::STAGE_UPDATED, [
            'stageId' => $stage->id,
            'position' => $position,
            'stage' => $this->stagePayloadMapper->toPayload($stage, $this->tripStateManager->getLocale($tripId) ?? 'en'),
        ]);
    }
}
