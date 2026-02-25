<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class TripUpdatePublisher implements TripUpdatePublisherInterface
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function publish(string $tripId, MercureEventType $type, array $data = []): void
    {
        $update = new Update(
            topics: [\sprintf('/trips/%s', $tripId)],
            data: json_encode(['type' => $type->value, 'data' => $data], \JSON_THROW_ON_ERROR),
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
}
