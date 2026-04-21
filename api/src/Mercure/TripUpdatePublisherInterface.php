<?php

declare(strict_types=1);

namespace App\Mercure;

use App\ApiResource\Stage;
use App\Enum\ComputationName;

/**
 * Publishes real-time trip computation events to subscribed clients via SSE.
 *
 * Exposes two complementary event modes:
 *
 * - **Mode 1 — Initial analysis (Act 2):** `publishComputationStepCompleted()` feeds
 *   a narrative progress bar without leaking business data, and `publishTripReady()`
 *   delivers the fully enriched trip payload in a single event so the frontend can
 *   swap state atomically.
 * - **Mode 2 — Inline modifications (Act 3):** `publishStageUpdated()` publishes the
 *   updated stage data so the frontend mutates a single slice of the store.
 */
interface TripUpdatePublisherInterface
{
    /** @param array<string, mixed> $data */
    public function publish(string $tripId, MercureEventType $type, array $data = []): void;

    public function publishValidationError(string $tripId, string $code, string $message): void;

    public function publishComputationError(string $tripId, string $computation, string $message, bool $retryable = true): void;

    /** @param array<string, string> $computationStatus */
    public function publishTripComplete(string $tripId, array $computationStatus): void;

    /**
     * Publishes a progress-only event signalling a single computation step finished.
     *
     * The payload carries no business data — it is only used to drive the UI progress bar.
     */
    public function publishComputationStepCompleted(
        string $tripId,
        ComputationName $step,
        int $completed,
        int $total,
    ): void;

    /**
     * Publishes the single terminal event of Mode 1 with the fully enriched trip payload.
     *
     * @param list<Stage>                                                  $stages
     * @param array{status: array<string, string>, aiOverview?: ?string}   $summary additional aggregate metadata
     */
    public function publishTripReady(string $tripId, array $stages, array $summary): void;

    /**
     * Publishes a stage-scoped update event for Mode 2 (inline modifications).
     *
     * Only the updated stage is carried over the wire so the frontend can
     * perform a targeted mutation on its store without rebuilding the whole trip.
     */
    public function publishStageUpdated(string $tripId, Stage $stage): void;
}
