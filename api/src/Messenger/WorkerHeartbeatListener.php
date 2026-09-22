<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Health\WorkerHeartbeat;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Clock\ClockInterface;

/**
 * Publishes this consumer's heartbeat so /api/health can tell a live worker from a dead
 * one (ADR-075). Only `messenger:consume` dispatches WorkerRunningEvent, so nothing here
 * runs in the HTTP process.
 */
#[AsEventListener(event: WorkerRunningEvent::class)]
final class WorkerHeartbeatListener
{
    private ?int $lastBeatAt = null;

    public function __construct(
        private readonly WorkerHeartbeat $heartbeat,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(WorkerRunningEvent $event): void
    {
        $now = $this->clock->now()->getTimestamp();

        // The event fires on every idle loop (once a second) and after every message.
        if (null !== $this->lastBeatAt && $now - $this->lastBeatAt < WorkerHeartbeat::BEAT_INTERVAL) {
            return;
        }

        $this->lastBeatAt = $now;

        try {
            // One member per container process; the hostname is unique per Compose replica.
            $this->heartbeat->beat(\sprintf('%s:%d', gethostname(), getmypid()));
        } catch (\Throwable) {
            // Observability must never create an outage: letting this escape would bubble
            // through Worker::run() and kill the consumer. Redis being unreachable is
            // already reported by the probe's own `redis` dependency.
        }
    }
}
