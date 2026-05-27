<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\EventListener\RequestIdListener;
use App\Messenger\CorrelationIdStamp;
use App\Messenger\SendCorrelationIdMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

#[CoversClass(SendCorrelationIdMiddleware::class)]
final class SendCorrelationIdMiddlewareTest extends TestCase
{
    public function testStampsEnvelopeFromRequestAttribute(): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE, 'req-stamp');

        $stack = new RequestStack();
        $stack->push($request);

        $middleware = new SendCorrelationIdMiddleware($stack);

        $envelope = new Envelope(new \stdClass());
        $captured = null;
        $result = $middleware->handle($envelope, $this->stackOf(static function (Envelope $env) use (&$captured): Envelope {
            $captured = $env;

            return $env;
        }));

        self::assertNotNull($captured);
        $stamp = $captured->last(CorrelationIdStamp::class);
        self::assertInstanceOf(CorrelationIdStamp::class, $stamp);
        self::assertSame('req-stamp', $stamp->correlationId);
        // The returned envelope downstream is the stamped one.
        self::assertNotNull($result->last(CorrelationIdStamp::class));
    }

    public function testSkipsWhenStampAlreadyPresent(): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE, 'should-not-override');

        $stack = new RequestStack();
        $stack->push($request);

        $middleware = new SendCorrelationIdMiddleware($stack);

        $envelope = new Envelope(new \stdClass(), [new CorrelationIdStamp('original')]);
        $captured = null;
        $middleware->handle($envelope, $this->stackOf(static function (Envelope $env) use (&$captured): Envelope {
            $captured = $env;

            return $env;
        }));

        self::assertNotNull($captured);
        $stamp = $captured->last(CorrelationIdStamp::class);
        self::assertInstanceOf(CorrelationIdStamp::class, $stamp);
        self::assertSame('original', $stamp->correlationId);
    }

    public function testIsNoopOutsideHttpContext(): void
    {
        // Empty RequestStack → CLI / worker chaining context.
        $middleware = new SendCorrelationIdMiddleware(new RequestStack());

        $envelope = new Envelope(new \stdClass());
        $captured = null;
        $middleware->handle($envelope, $this->stackOf(static function (Envelope $env) use (&$captured): Envelope {
            $captured = $env;

            return $env;
        }));

        self::assertNotNull($captured);
        self::assertNull($captured->last(CorrelationIdStamp::class));
    }

    public function testIsNoopWhenRequestAttributeMissing(): void
    {
        $request = new Request();
        $stack = new RequestStack();
        $stack->push($request);

        $middleware = new SendCorrelationIdMiddleware($stack);

        $envelope = new Envelope(new \stdClass());
        $captured = null;
        $middleware->handle($envelope, $this->stackOf(static function (Envelope $env) use (&$captured): Envelope {
            $captured = $env;

            return $env;
        }));

        self::assertNotNull($captured);
        self::assertNull($captured->last(CorrelationIdStamp::class));
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
