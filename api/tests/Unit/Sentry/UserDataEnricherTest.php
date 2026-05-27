<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sentry;

use App\Entity\User;
use App\EventListener\RequestIdListener;
use App\Sentry\UserDataEnricher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventId;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(UserDataEnricher::class)]
#[AllowMockObjectsWithoutExpectations]
final class UserDataEnricherTest extends TestCase
{
    public function testIsNoopWhenHubIsNull(): void
    {
        $enricher = new UserDataEnricher();

        // No exception, no side effect.
        $enricher->onRequest($this->event(new Request()));
        $this->expectNotToPerformAssertions();
    }

    public function testIgnoresSubRequests(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('configureScope');

        $enricher = new UserDataEnricher($hub);
        $enricher->onRequest($this->event(new Request(), HttpKernelInterface::SUB_REQUEST));
    }

    public function testEnrichesScopeWithCorrelationAndTripTags(): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE, 'req-abc');
        $request->attributes->set('tripId', 'trip-123');
        $request->attributes->set('computationName', 'pacing');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $scope = new Scope();
        $enricher = new UserDataEnricher($this->capturingHub($scope), $security);
        $enricher->onRequest($this->event($request));

        $event = $this->applyScope($scope);
        self::assertSame('req-abc', $event->getTags()['request_id'] ?? null);
        self::assertSame('trip-123', $event->getTags()['trip_id'] ?? null);
        self::assertSame('pacing', $event->getTags()['computation_name'] ?? null);
        self::assertNull($event->getUser());
    }

    public function testEnrichesScopeWithUserIdWhenAuthenticated(): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE, 'req-xyz');

        $user = new User('test@example.com');
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $scope = new Scope();
        $enricher = new UserDataEnricher($this->capturingHub($scope), $security);
        $enricher->onRequest($this->event($request));

        $event = $this->applyScope($scope);
        $userBag = $event->getUser();
        self::assertNotNull($userBag);
        self::assertSame($user->getId()->toRfc4122(), $userBag->getId());
    }

    public function testTagsTripIdFromIdAttributeOnTripRoute(): void
    {
        $request = Request::create('/trips/11111111-1111-1111-1111-111111111111');
        $request->attributes->set('id', '11111111-1111-1111-1111-111111111111');

        $scope = new Scope();
        $enricher = new UserDataEnricher($this->capturingHub($scope));
        $enricher->onRequest($this->event($request));

        $event = $this->applyScope($scope);
        self::assertSame(
            '11111111-1111-1111-1111-111111111111',
            $event->getTags()['trip_id'] ?? null,
        );
    }

    public function testDoesNotTagTripIdFromIdAttributeOnNonTripRoute(): void
    {
        $request = Request::create('/stages/22222222-2222-2222-2222-222222222222');
        $request->attributes->set('id', '22222222-2222-2222-2222-222222222222');

        $scope = new Scope();
        $enricher = new UserDataEnricher($this->capturingHub($scope));
        $enricher->onRequest($this->event($request));

        $event = $this->applyScope($scope);
        self::assertArrayNotHasKey('trip_id', $event->getTags());
    }

    public function testDoesNotLeakPiiIntoTagsWhenAttributesAreMissing(): void
    {
        $request = new Request();
        $scope = new Scope();
        $enricher = new UserDataEnricher($this->capturingHub($scope));
        $enricher->onRequest($this->event($request));

        $event = $this->applyScope($scope);
        self::assertArrayNotHasKey('request_id', $event->getTags());
        self::assertArrayNotHasKey('trip_id', $event->getTags());
        self::assertArrayNotHasKey('computation_name', $event->getTags());
    }

    private function event(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, $type);
    }

    private function capturingHub(Scope $scope): HubInterface
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->method('configureScope')->willReturnCallback(
            static function (callable $cb) use ($scope): void {
                $cb($scope);
            },
        );

        return $hub;
    }

    private function applyScope(Scope $scope): Event
    {
        $event = Event::createEvent(EventId::generate());
        $scope->applyToEvent($event);

        return $event;
    }
}
