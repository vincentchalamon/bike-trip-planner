<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Message\AnalyzeWind;
use App\Message\RecalculateStages;
use App\Message\ResolveStageLabels;
use App\Message\SendPushNotification;
use App\Messenger\StaleMessageMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;

/**
 * The one place that decides a message is superseded (ADR-073).
 *
 * The three tests this class absorbs — one per handler that used to carry its own copy of the
 * comparison — each asserted only that nothing was published. What they never asserted is the
 * part that mattered: that the handler is not reached at all, and that the middleware writes
 * nothing.
 */
#[CoversClass(StaleMessageMiddleware::class)]
final class StaleMessageMiddlewareTest extends TestCase
{
    #[Test]
    public function aMessageTheTripHasMovedPastNeverReachesItsHandler(): void
    {
        $reached = false;
        $this->middleware(current: 5)->handle(
            $this->consumed(new AnalyzeWind('trip-1', generation: 3)),
            $this->stackOf(function (Envelope $e) use (&$reached): Envelope {
                $reached = true;

                return $e;
            }),
        );

        self::assertFalse($reached, 'The handler chain must not be entered at all.');
    }

    /**
     * Not by throwing. An exception would hand the message to the retry strategy and then to
     * the `failed` transport, for something that is not a failure.
     */
    #[Test]
    public function aDiscardedMessageIsAckedRatherThanFailed(): void
    {
        $envelope = $this->consumed(new RecalculateStages('trip-1', [], generation: 2));

        $returned = $this->middleware(current: 9)->handle(
            $envelope,
            $this->stackOf(static fn (Envelope $e): Envelope => $e),
        );

        self::assertSame($envelope, $returned);
    }

    #[Test]
    #[DataProvider('passThroughCases')]
    public function everythingElseIsHandedOn(?int $messageGeneration, ?int $current, string $why): void
    {
        $reached = false;
        $this->middleware($current)->handle(
            $this->consumed(new ResolveStageLabels('trip-1', $messageGeneration)),
            $this->stackOf(function (Envelope $e) use (&$reached): Envelope {
                $reached = true;

                return $e;
            }),
        );

        self::assertTrue($reached, $why);
    }

    /**
     * @return iterable<string, array{0: ?int, 1: ?int, 2: string}>
     */
    public static function passThroughCases(): iterable
    {
        yield 'no generation on the message' => [null, 5, 'Its sender did not scope it to one.'];
        yield 'no generation on the trip' => [3, null, 'The trip does not exist.'];
        yield 'same generation' => [5, 5, 'Current is not newer.'];
        // Three processors read `current()` outside the write lock, so a message can carry a
        // generation above it. Kept deliberately (ADR-073).
        yield 'a generation above the current one' => [7, 5, 'The strict comparison lets it through.'];
    }

    /**
     * A push notification has no trip and no generation, so the guard has nothing to compare
     * and must not get in its way.
     */
    #[Test]
    public function aMessageThatBelongsToNoGenerationIsUntouched(): void
    {
        $reached = false;
        $this->middleware(current: 99)->handle(
            $this->consumed(new SendPushNotification('title', 'body')),
            $this->stackOf(function (Envelope $e) use (&$reached): Envelope {
                $reached = true;

                return $e;
            }),
        );

        self::assertTrue($reached);
    }

    /**
     * On the dispatch side the message has just been built against the current generation, so
     * there is nothing to compare — and the tracker must not even be asked.
     */
    #[Test]
    public function theGuardDoesNotRunOnTheDispatchSide(): void
    {
        $generations = $this->createMock(TripGenerationTrackerInterface::class);
        $generations->expects($this->never())->method('current');

        $reached = false;
        new StaleMessageMiddleware($generations, new NullLogger())->handle(
            new Envelope(new AnalyzeWind('trip-1', generation: 3)),
            $this->stackOf(function (Envelope $e) use (&$reached): Envelope {
                $reached = true;

                return $e;
            }),
        );

        self::assertTrue($reached);
    }

    private function middleware(?int $current): StaleMessageMiddleware
    {
        $generations = $this->createStub(TripGenerationTrackerInterface::class);
        $generations->method('current')->willReturn($current);

        return new StaleMessageMiddleware($generations, new NullLogger());
    }

    private function consumed(object $message): Envelope
    {
        return new Envelope($message, [new ConsumedByWorkerStamp()]);
    }

    private function stackOf(\Closure $handler): StackInterface
    {
        return new readonly class ($handler) implements StackInterface {
            public function __construct(private \Closure $handler)
            {
            }

            #[\Override]
            public function next(): MiddlewareInterface
            {
                return new readonly class ($this->handler) implements MiddlewareInterface {
                    public function __construct(private \Closure $handler)
                    {
                    }

                    #[\Override]
                    public function handle(Envelope $envelope, StackInterface $stack): Envelope
                    {
                        return ($this->handler)($envelope, $stack);
                    }
                };
            }
        };
    }
}
