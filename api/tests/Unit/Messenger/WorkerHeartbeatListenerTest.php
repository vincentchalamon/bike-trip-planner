<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Health\WorkerHeartbeat;
use App\Messenger\WorkerHeartbeatListener;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

#[AllowMockObjectsWithoutExpectations]
final class WorkerHeartbeatListenerTest extends TestCase
{
    #[Test]
    public function beatsAtMostOncePerInterval(): void
    {
        $heartbeat = $this->createMock(WorkerHeartbeat::class);
        $heartbeat->expects(self::once())->method('beat');

        $clock = new MockClock();
        $listener = new WorkerHeartbeatListener($heartbeat, $clock);

        // WorkerRunningEvent fires once a second while idle; without the throttle this
        // would be a Redis round-trip per loop.
        $listener($this->event());
        $clock->sleep(5);
        $listener($this->event());
    }

    #[Test]
    public function beatsAgainOncePastTheInterval(): void
    {
        $heartbeat = $this->createMock(WorkerHeartbeat::class);
        $heartbeat->expects(self::exactly(2))->method('beat');

        $clock = new MockClock();
        $listener = new WorkerHeartbeatListener($heartbeat, $clock);

        $listener($this->event());
        $clock->sleep(WorkerHeartbeat::BEAT_INTERVAL + 1);
        $listener($this->event());
    }

    #[Test]
    public function aRedisOutageNeverEscapesAndKillsTheWorker(): void
    {
        $heartbeat = $this->createMock(WorkerHeartbeat::class);
        $heartbeat->method('beat')->willThrowException(new \RuntimeException('Connection refused'));

        $listener = new WorkerHeartbeatListener($heartbeat, new MockClock());

        // An exception here would bubble through Worker::run() and stop the consumer:
        // observability would have created the outage it exists to report.
        $listener($this->event());

        $this->expectNotToPerformAssertions();
    }

    private function event(): WorkerRunningEvent
    {
        return new WorkerRunningEvent($this->createMock(Worker::class), true);
    }
}
