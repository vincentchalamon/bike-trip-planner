<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logger;

use App\EventListener\RequestIdListener;
use App\Logger\CorrelationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CorrelationContext::class)]
final class CorrelationContextTest extends TestCase
{
    #[Test]
    public function workerOverrideWinsOverAttributeAndHeader(): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE, 'attribute-value');
        $request->headers->set(RequestIdListener::HEADER, 'header-value');

        $context = new CorrelationContext(new RequestStack([$request]));
        $context->setOverrideRequestId('worker-value');

        self::assertSame('worker-value', $context->requestId());
    }

    #[Test]
    public function attributeWinsOverHeader(): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE, 'attribute-value');
        $request->headers->set(RequestIdListener::HEADER, 'header-value');

        self::assertSame('attribute-value', new CorrelationContext(new RequestStack([$request]))->requestId());
    }

    #[Test]
    public function headerIsTheLastResort(): void
    {
        $request = new Request();
        $request->headers->set(RequestIdListener::HEADER, 'header-value');

        self::assertSame('header-value', new CorrelationContext(new RequestStack([$request]))->requestId());
    }

    #[Test]
    public function emptyOverrideFallsThroughToTheRequest(): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE, 'attribute-value');

        $context = new CorrelationContext(new RequestStack([$request]));
        $context->setOverrideRequestId('');

        self::assertSame('attribute-value', $context->requestId());
    }

    #[Test]
    public function noContextYieldsNull(): void
    {
        $context = new CorrelationContext(new RequestStack());

        self::assertNull($context->requestId());
        self::assertNull($context->tripId());
    }

    #[Test]
    public function readsTheMainRequestNotASubRequest(): void
    {
        $main = new Request();
        $main->attributes->set(RequestIdListener::ATTRIBUTE, 'main-value');
        $main->attributes->set('tripId', 'main-trip');

        $sub = new Request();
        $sub->attributes->set(RequestIdListener::ATTRIBUTE, 'sub-value');
        $sub->attributes->set('tripId', 'sub-trip');

        $context = new CorrelationContext(new RequestStack([$main, $sub]));

        self::assertSame('main-value', $context->requestId());
        self::assertSame('main-trip', $context->tripId());
    }

    #[Test]
    public function tripIdPrefersTheExplicitAttributes(): void
    {
        $request = Request::create('/trips/a/stages/0');
        $request->attributes->set('id', 'generic-id');
        $request->attributes->set('trip_id', 'snake-id');
        $request->attributes->set('tripId', 'camel-id');

        self::assertSame('camel-id', new CorrelationContext(new RequestStack([$request]))->tripId());
    }

    #[Test]
    public function tripIdReadsGenericIdOnTripPathsOnly(): void
    {
        $onTrip = Request::create('/trips/22222222-2222-7000-9000-000000000002');
        $onTrip->attributes->set('id', Uuid::fromString('22222222-2222-7000-9000-000000000002'));

        $onUser = Request::create('/users/33333333-3333-7000-9000-000000000003');
        $onUser->attributes->set('id', '33333333-3333-7000-9000-000000000003');

        self::assertSame('22222222-2222-7000-9000-000000000002', new CorrelationContext(new RequestStack([$onTrip]))->tripId());
        self::assertNull(new CorrelationContext(new RequestStack([$onUser]))->tripId());
    }
}
