<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\McpEnvelopeThrottleListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class McpEnvelopeThrottleListenerTest extends TestCase
{
    /** Past the ceiling, an HTTP client is told so the way HTTP says it. */
    #[Test]
    public function pastTheCeilingAnAddressGetsA429WithRetryAfter(): void
    {
        $listener = $this->listener(2);

        $this->dispatch($listener, '/mcp', '203.0.113.7');
        $this->dispatch($listener, '/mcp', '203.0.113.7');
        $event = $this->dispatch($listener, '/mcp', '203.0.113.7');

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
    }

    #[Test]
    public function addressesDoNotShareABucket(): void
    {
        $listener = $this->listener(1);

        self::assertNull($this->dispatch($listener, '/mcp', '203.0.113.7')->getResponse());
        self::assertNull($this->dispatch($listener, '/mcp', '198.51.100.9')->getResponse());
    }

    /** Only /mcp: the rest of the API has its own limiters and must not spend this one. */
    #[Test]
    public function otherPathsAreNotCounted(): void
    {
        $listener = $this->listener(1);

        foreach (range(1, 5) as $ignored) {
            self::assertNull($this->dispatch($listener, '/trips', '203.0.113.7')->getResponse());
        }

        self::assertNull($this->dispatch($listener, '/mcp', '203.0.113.7')->getResponse());
    }

    private function dispatch(McpEnvelopeThrottleListener $listener, string $path, string $ip): RequestEvent
    {
        $request = Request::create($path, 'POST', server: ['REMOTE_ADDR' => $ip]);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $listener($event);

        return $event;
    }

    private function listener(int $limit): McpEnvelopeThrottleListener
    {
        return new McpEnvelopeThrottleListener(new RateLimiterFactory(
            ['id' => 'mcp_envelope', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        ));
    }
}
