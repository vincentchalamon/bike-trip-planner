<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mercure;

use App\Mercure\MercureSubscriberListener;
use App\Mercure\MercureTokenIssuer;
use PHPUnit\Framework\Attributes\DataProvider;
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
            new MercureTokenIssuer('test-mercure-secret-key'),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tripEndpointProvider(): iterable
    {
        yield 'GET /trips/{id}/detail' => ['GET', '/trips/'.self::TRIP_UUID.'/detail'];
        yield 'PATCH /trips/{id}' => ['PATCH', '/trips/'.self::TRIP_UUID];
        yield 'POST /trips/{id}/duplicate' => ['POST', '/trips/'.self::TRIP_UUID.'/duplicate'];
    }

    #[Test]
    #[DataProvider('tripEndpointProvider')]
    public function setsCookieAndHeaderForTripEndpoints(string $method, string $path): void
    {
        $request = Request::create($path, $method);
        $response = new Response('ok');
        $event = $this->createResponseEvent($request, $response);

        $this->listener->__invoke($event);

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('mercureAuthorization', $cookies[0]->getName());
        self::assertNotEmpty($response->headers->get('X-Mercure-Token'));
        self::assertSame($cookies[0]->getValue(), $response->headers->get('X-Mercure-Token'));
    }

    #[Test]
    public function setsCookieAndHeaderForPostTripsFromResponseBody(): void
    {
        $request = Request::create('/trips', 'POST');
        $response = new JsonResponse(['id' => self::TRIP_UUID, 'computationStatus' => []]);
        $event = $this->createResponseEvent($request, $response);

        $this->listener->__invoke($event);

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('mercureAuthorization', $cookies[0]->getName());
        self::assertNotEmpty($response->headers->get('X-Mercure-Token'));
    }

    #[Test]
    public function setsCookieAndHeaderForGpxUpload(): void
    {
        $request = Request::create('/trips/gpx-upload', 'POST');
        $response = new JsonResponse(['id' => self::TRIP_UUID]);
        $event = $this->createResponseEvent($request, $response);

        $this->listener->__invoke($event);

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertNotEmpty($response->headers->get('X-Mercure-Token'));
    }

    #[Test]
    public function doesNotSetCookieForUnmatchedEndpoints(): void
    {
        $request = Request::create('/trips', 'GET');
        $response = new Response('ok');
        $event = $this->createResponseEvent($request, $response);

        $this->listener->__invoke($event);

        self::assertEmpty($response->headers->getCookies());
        self::assertNull($response->headers->get('X-Mercure-Token'));
    }

    #[Test]
    public function doesNotSetCookieForSubRequests(): void
    {
        $request = Request::create('/trips/'.self::TRIP_UUID.'/detail', 'GET');
        $response = new Response('ok');
        $kernel = $this->createStub(KernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, $response);

        $this->listener->__invoke($event);

        self::assertEmpty($response->headers->getCookies());
        self::assertNull($response->headers->get('X-Mercure-Token'));
    }

    #[Test]
    public function doesNotSetCookieWhenResponseBodyHasNoId(): void
    {
        $request = Request::create('/trips', 'POST');
        $response = new JsonResponse(['error' => 'bad request']);
        $event = $this->createResponseEvent($request, $response);

        $this->listener->__invoke($event);

        self::assertEmpty($response->headers->getCookies());
        self::assertNull($response->headers->get('X-Mercure-Token'));
    }

    private function createResponseEvent(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createMock(KernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
