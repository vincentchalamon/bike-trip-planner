<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\HideForbiddenAsNotFoundListener;
use App\Exception\TripNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(HideForbiddenAsNotFoundListener::class)]
final class HideForbiddenAsNotFoundListenerTest extends TestCase
{
    #[Test]
    public function doesNotSwapNonAccessDeniedException(): void
    {
        // A validation error or any other throwable must not be silently turned
        // into a 404, even for a fully-authenticated caller.
        $original = new \RuntimeException('unrelated');

        $event = $this->handle($original, fullyAuthenticated: true);

        self::assertSame($original, $event->getThrowable());
    }

    #[Test]
    public function doesNotSwapWhenCallerIsNotFullyAuthenticated(): void
    {
        // An anonymous (or remembered) denial stays an AccessDeniedException so the
        // firewall entry point can turn it into 401, not 404. The functional
        // `unauthenticatedRequestReturns401` test exercises the access_control rule
        // that fires earlier, not this guard.
        $original = new AccessDeniedException();

        $event = $this->handle($original, fullyAuthenticated: false);

        self::assertSame($original, $event->getThrowable());
    }

    #[Test]
    public function swapsAccessDeniedToTripNotFoundForFullyAuthenticatedCaller(): void
    {
        $event = $this->handle(new AccessDeniedException(), fullyAuthenticated: true);

        $throwable = $event->getThrowable();
        self::assertInstanceOf(TripNotFoundException::class, $throwable);
        // No `previous` link: the firewall listener walks getPrevious() and would
        // otherwise re-detect the wrapped AccessDeniedException and re-handle it.
        self::assertNull($throwable->getPrevious());
    }

    private function handle(\Throwable $throwable, bool $fullyAuthenticated): ExceptionEvent
    {
        $trustResolver = $this->createStub(AuthenticationTrustResolverInterface::class);
        $trustResolver->method('isFullFledged')->willReturn($fullyAuthenticated);

        $listener = new HideForbiddenAsNotFoundListener(new TokenStorage(), $trustResolver);

        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        $listener($event);

        return $event;
    }
}
