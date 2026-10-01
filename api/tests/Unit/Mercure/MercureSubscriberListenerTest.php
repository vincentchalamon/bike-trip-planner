<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mercure;

use App\Mercure\MercureSubscriberListener;
use App\Mercure\MercureTokenIssuer;
use App\Mercure\TripSubscription;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class MercureSubscriberListenerTest extends TestCase
{
    private const string TRIP_UUID = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    private MercureSubscriberListener $listener;

    #[\Override]
    protected function setUp(): void
    {
        $this->listener = new MercureSubscriberListener(
            new MercureTokenIssuer(TestHubFactory::create()),
        );
    }

    #[Test]
    public function setsCookieForTheStampedTrip(): void
    {
        $request = Request::create('/trips/'.self::TRIP_UUID.'/detail');
        $request->attributes->set(TripSubscription::ATTRIBUTE, self::TRIP_UUID);
        $response = new Response('ok');

        $this->listener->__invoke($this->createResponseEvent($request, $response));

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('__Secure-mercure_access_token', $cookies[0]->getName());
        self::assertNotEmpty($cookies[0]->getValue());
    }

    #[Test]
    public function ignoresTripUrlsAndBodiesWhenNothingIsStamped(): void
    {
        $request = Request::create('/trips/'.self::TRIP_UUID.'/detail');
        $response = new JsonResponse(['id' => self::TRIP_UUID]);

        $this->listener->__invoke($this->createResponseEvent($request, $response));

        self::assertEmpty($response->headers->getCookies());
    }

    #[Test]
    public function doesNotSetCookieOnAFailedResponse(): void
    {
        $request = Request::create('/trips/'.self::TRIP_UUID, 'PATCH');
        $request->attributes->set(TripSubscription::ATTRIBUTE, self::TRIP_UUID);
        $response = new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->listener->__invoke($this->createResponseEvent($request, $response));

        self::assertEmpty($response->headers->getCookies());
    }

    #[Test]
    public function doesNotSetCookieForSubRequests(): void
    {
        $request = Request::create('/trips/'.self::TRIP_UUID.'/detail');
        $request->attributes->set(TripSubscription::ATTRIBUTE, self::TRIP_UUID);
        $response = new Response('ok');
        $kernel = $this->createStub(KernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, $response);

        $this->listener->__invoke($event);

        self::assertEmpty($response->headers->getCookies());
    }

    private function createResponseEvent(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createStub(KernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
