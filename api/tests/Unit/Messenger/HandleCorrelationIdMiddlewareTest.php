<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Logger\CorrelationIdProcessor;
use App\Messenger\CorrelationIdStamp;
use App\Messenger\HandleCorrelationIdMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;

#[CoversClass(HandleCorrelationIdMiddleware::class)]
final class HandleCorrelationIdMiddlewareTest extends TestCase
{
    public function testSetsOverrideDuringHandleAndClearsAfter(): void
    {
        $processor = $this->processor();
        $middleware = new HandleCorrelationIdMiddleware($processor);

        $observed = null;
        $envelope = new Envelope(new \stdClass(), [
            new ConsumedByWorkerStamp(),
            new CorrelationIdStamp('req-abc'),
        ]);

        $stack = $this->stackOf(function (Envelope $env, StackInterface $s) use ($processor, &$observed): Envelope {
            $observed = $processor->getOverrideRequestId();

            return $env;
        });

        $middleware->handle($envelope, $stack);

        self::assertSame('req-abc', $observed);
        self::assertNull($processor->getOverrideRequestId());
    }

    public function testClearsOverrideEvenWhenHandlerThrows(): void
    {
        $processor = $this->processor();
        $middleware = new HandleCorrelationIdMiddleware($processor);

        $envelope = new Envelope(new \stdClass(), [
            new ConsumedByWorkerStamp(),
            new CorrelationIdStamp('req-throws'),
        ]);

        $stack = $this->stackOf(static function (): Envelope {
            throw new \RuntimeException('boom');
        });

        try {
            $middleware->handle($envelope, $stack);
            self::fail('Expected RuntimeException to propagate.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame('boom', $runtimeException->getMessage());
        }

        self::assertNull($processor->getOverrideRequestId());
    }

    public function testIsNoopWhenCorrelationStampMissing(): void
    {
        $processor = $this->processor();
        $processor->setOverrideRequestId('pre-existing');

        $middleware = new HandleCorrelationIdMiddleware($processor);

        $envelope = new Envelope(new \stdClass(), [new ConsumedByWorkerStamp()]);
        $stack = $this->stackOf(static fn (Envelope $env): Envelope => $env);

        $middleware->handle($envelope, $stack);

        // Override is left untouched (not cleared) when there's nothing to set.
        self::assertSame('pre-existing', $processor->getOverrideRequestId());
    }

    public function testIsNoopForSynchronousDispatch(): void
    {
        $processor = $this->processor();
        $processor->setOverrideRequestId('pre-existing');

        $middleware = new HandleCorrelationIdMiddleware($processor);

        // No ConsumedByWorkerStamp → sync dispatch from request context.
        $envelope = new Envelope(new \stdClass(), [new CorrelationIdStamp('ignored')]);
        $stack = $this->stackOf(static fn (Envelope $env): Envelope => $env);

        $middleware->handle($envelope, $stack);

        self::assertSame('pre-existing', $processor->getOverrideRequestId());
    }

    private function processor(): CorrelationIdProcessor
    {
        return new CorrelationIdProcessor(
            new RequestStack(),
            $this->createStub(Security::class),
        );
    }

    private function stackOf(\Closure $handler): StackInterface
    {
        $inner = new readonly class ($handler) implements MiddlewareInterface {
            public function __construct(private \Closure $handler)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return ($this->handler)($envelope, $stack);
            }
        };

        return new StackMiddleware([$inner]);
    }
}
