<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\User;
use App\EventListener\OAuthEndpointThrottleListener;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The budget on the two OAuth endpoints, and who shares one.
 *
 * Proven here rather than end to end: the rate-limiter pool falls back to an array adapter
 * under test, one per process, so exhausting a real budget through the HTTP stack would
 * spend it for every other OAuth case in the same run — the same reason the anonymous share
 * surface is tested this way.
 *
 * What matters is not that a limiter exists but WHO gets counted. A caller whose address
 * cannot be resolved must land in the same bucket as the other unresolvable ones, not in a
 * fresh one each time: failing open there is reachable by misconfiguring trusted proxies,
 * and this repository has already shipped that bug once.
 */
#[AllowMockObjectsWithoutExpectations]
final class OAuthEndpointThrottleListenerTest extends TestCase
{
    private MockObject&Security $security;

    #[\Override]
    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->security->method('getUser')->willReturn(new User('owner@example.com'));
    }

    #[Test]
    public function theTokenEndpointStopsAcceptingPastItsBudget(): void
    {
        $listener = $this->listener(tokenLimit: 1);

        $listener($this->event('oauth2_token', '203.0.113.7'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->event('oauth2_token', '203.0.113.7'));
    }

    /**
     * Two callers whose IP cannot be resolved share one budget. The alternative — a fresh
     * bucket per unresolvable caller — is the same as no limit at all.
     */
    #[Test]
    public function callersWithNoResolvableAddressShareOneBudget(): void
    {
        $listener = $this->listener(tokenLimit: 1);

        $listener($this->event('oauth2_token', null));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->event('oauth2_token', null));
    }

    /**
     * And they are not lumped in with someone whose address IS known: an unresolvable caller
     * must not be able to spend a real address's budget.
     */
    #[Test]
    public function anUnresolvableCallerDoesNotSpendSomeoneElsesBudget(): void
    {
        $listener = $this->listener(tokenLimit: 1);

        $listener($this->event('oauth2_token', null));
        $listener($this->event('oauth2_token', '203.0.113.7'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * The authorization endpoint has a user behind it, so that is what it counts — two
     * browsers on one address are two people.
     */
    #[Test]
    public function theAuthorizationEndpointCountsTheUser(): void
    {
        $listener = $this->listener(authorizeLimit: 1);

        $listener($this->event('oauth2_authorize', '203.0.113.7'));

        $this->expectException(TooManyRequestsHttpException::class);
        // A different address, the same person: still spent.
        $listener($this->event('oauth2_authorize', '198.51.100.4'));
    }

    #[Test]
    public function anyOtherRouteIsLeftAlone(): void
    {
        $listener = $this->listener(tokenLimit: 1);

        $listener($this->event('api_trips_get_collection', '203.0.113.7'));
        $listener($this->event('api_trips_get_collection', '203.0.113.7'));

        $this->expectNotToPerformAssertions();
    }

    private function listener(int $tokenLimit = 10, int $authorizeLimit = 10): OAuthEndpointThrottleListener
    {
        return new OAuthEndpointThrottleListener(
            $this->limiter('oauth_token', $tokenLimit),
            $this->limiter('oauth_authorize', $authorizeLimit),
            $this->security,
        );
    }

    private function limiter(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    private function event(string $route, ?string $clientIp): RequestEvent
    {
        $request = Request::create('/oauth/token');
        $request->attributes->set('_route', $route);

        if (null === $clientIp) {
            // What a misconfigured trusted-proxy setup looks like from here.
            $request->server->remove('REMOTE_ADDR');
        } else {
            $request->server->set('REMOTE_ADDR', $clientIp);
        }

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
