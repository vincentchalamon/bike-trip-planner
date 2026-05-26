<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Logger\CorrelationIdProcessor;
use App\Messenger\CorrelationIdStamp;
use App\Messenger\HandleCorrelationIdMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;

final class HandleCorrelationIdMiddlewareTest extends TestCase
{
    private function buildProcessor(): CorrelationIdProcessor
    {
        return new CorrelationIdProcessor(
            new RequestStack(),
            $this->createMock(Security::class),
        );
    }

    #[Test]
    public function setsOverrideDuringHandlerAndClearsItOnHappyPath(): void
    {
        $processor = $this->buildProcessor();
        $middleware = new HandleCorrelationIdMiddleware($processor);

        $observed = null;
        $envelope = new Envelope(new \stdClass(), [
            new ConsumedByWorkerStamp(),
            new CorrelationIdStamp('req-123'),
        ]);

        $stack = $this->stackThat(function (Envelope $e) use (&$observed, $processor): Envelope {
            $observed = $processor->getOverrideRequestId();

            return $e;
        });

        $middleware->handle($envelope, $stack);

        self::assertSame('req-123', $observed, 'Override must be active inside the handler.');
        self::assertNull($processor->getOverrideRequestId(), 'Override must be cleared after handling.');
    }

    #[Test]
    public function clearsOverrideEvenWhenHandlerThrows(): void
    {
        $processor = $this->buildProcessor();
        $middleware = new HandleCorrelationIdMiddleware($processor);

        $envelope = new Envelope(new \stdClass(), [
            new ConsumedByWorkerStamp(),
            new CorrelationIdStamp('req-456'),
        ]);

        $stack = $this->stackThat(static function (Envelope $e): Envelope {
            throw new \RuntimeException('handler boom');
        });

        try {
            $middleware->handle($envelope, $stack);
            self::fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('handler boom', $e->getMessage());
        }

        self::assertNull(
            $processor->getOverrideRequestId(),
            'Override must be cleared even when the handler throws — otherwise subsequent messages leak the stale request_id.',
        );
    }

    #[Test]
    public function skipsOverrideWhenMessageHasNoCorrelationStamp(): void
    {
        $processor = $this->buildProcessor();
        $processor->setOverrideRequestId('pre-existing'); // intentionally seeded

        $middleware = new HandleCorrelationIdMiddleware($processor);

        $envelope = new Envelope(new \stdClass(), [new ConsumedByWorkerStamp()]);

        $stack = $this->stackThat(static fn (Envelope $e): Envelope => $e);

        $middleware->handle($envelope, $stack);

        self::assertSame(
            'pre-existing',
            $processor->getOverrideRequestId(),
            'Middleware must not touch the override when no CorrelationIdStamp is present.',
        );
    }

    #[Test]
    public function skipsOverrideForSyncDispatchWithoutConsumedByWorkerStamp(): void
    {
        $processor = $this->buildProcessor();
        $middleware = new HandleCorrelationIdMiddleware($processor);

        $envelope = new Envelope(new \stdClass(), [new CorrelationIdStamp('req-789')]);

        $stack = $this->stackThat(static fn (Envelope $e): Envelope => $e);

        $middleware->handle($envelope, $stack);

        self::assertNull(
            $processor->getOverrideRequestId(),
            'Sync dispatches must not set the override — the processor resolves the id from RequestStack.',
        );
    }

    /**
     * Builds a `StackInterface` whose `next()` invokes the provided handler
     * on the envelope passed through `handle()`. Mirrors what Messenger does
     * internally so tests can assert the override state inside the handler.
     */
    private function stackThat(callable $handler): StackInterface
    {
        $middleware = new class($handler) implements MiddlewareInterface {
            public function __construct(private $handler)
            {
            }

            #[\Override]
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return ($this->handler)($envelope);
            }
        };

        return new class($middleware) implements StackInterface {
            public function __construct(private MiddlewareInterface $middleware)
            {
            }

            #[\Override]
            public function next(): MiddlewareInterface
            {
                return $this->middleware;
            }
        };
    }
}
