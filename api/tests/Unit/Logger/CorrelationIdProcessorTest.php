<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logger;

use App\Entity\User;
use App\EventListener\RequestIdListener;
use App\Logger\CorrelationIdProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class CorrelationIdProcessorTest extends TestCase
{
    private function buildRecord(): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: [],
            extra: [],
        );
    }

    #[Test]
    public function injectsRequestIdFromMainRequestAttribute(): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE, '0193e7c1-1234-7000-9000-abcdef000001');

        $stack = new RequestStack();
        $stack->push($request);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $processor = new CorrelationIdProcessor($stack, $security);

        $record = $processor($this->buildRecord());

        self::assertSame('0193e7c1-1234-7000-9000-abcdef000001', $record->extra['request_id']);
        self::assertArrayNotHasKey('user_id', $record->extra);
        self::assertArrayNotHasKey('trip_id', $record->extra);
    }

    #[Test]
    public function fallsBackToHeaderWhenAttributeMissing(): void
    {
        $request = new Request();
        $request->headers->set(RequestIdListener::HEADER, '0193e7c1-1234-7000-9000-abcdef000002');

        $stack = new RequestStack();
        $stack->push($request);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $processor = new CorrelationIdProcessor($stack, $security);

        $record = $processor($this->buildRecord());

        self::assertSame('0193e7c1-1234-7000-9000-abcdef000002', $record->extra['request_id']);
    }

    #[Test]
    public function injectsUserIdWhenAuthenticated(): void
    {
        $stack = new RequestStack();

        $user = $this->createMock(User::class);
        $userId = Uuid::v7();
        $user->method('getId')->willReturn($userId);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $processor = new CorrelationIdProcessor($stack, $security);

        $record = $processor($this->buildRecord());

        self::assertSame($userId->toRfc4122(), $record->extra['user_id']);
    }

    #[Test]
    public function injectsTripIdFromPathAttributes(): void
    {
        $request = new Request();
        $request->attributes->set('tripId', '11111111-1111-7000-9000-000000000001');

        $stack = new RequestStack();
        $stack->push($request);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $processor = new CorrelationIdProcessor($stack, $security);

        $record = $processor($this->buildRecord());

        self::assertSame('11111111-1111-7000-9000-000000000001', $record->extra['trip_id']);
    }

    #[Test]
    public function fallsBackToIdPathAttributeForTripId(): void
    {
        $request = new Request();
        $request->attributes->set('id', '22222222-2222-7000-9000-000000000002');

        $stack = new RequestStack();
        $stack->push($request);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $processor = new CorrelationIdProcessor($stack, $security);

        $record = $processor($this->buildRecord());

        self::assertSame('22222222-2222-7000-9000-000000000002', $record->extra['trip_id']);
    }

    #[Test]
    public function overrideRequestIdTakesPrecedenceOverRequestStack(): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE, 'attr-value');

        $stack = new RequestStack();
        $stack->push($request);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $processor = new CorrelationIdProcessor($stack, $security);
        $processor->setOverrideRequestId('worker-correlation-id');

        $record = $processor($this->buildRecord());

        self::assertSame('worker-correlation-id', $record->extra['request_id']);
        self::assertSame('worker-correlation-id', $processor->getOverrideRequestId());
    }

    #[Test]
    public function omitsRequestIdWhenNoContext(): void
    {
        $stack = new RequestStack();
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $processor = new CorrelationIdProcessor($stack, $security);

        $record = $processor($this->buildRecord());

        self::assertArrayNotHasKey('request_id', $record->extra);
        self::assertArrayNotHasKey('user_id', $record->extra);
        self::assertArrayNotHasKey('trip_id', $record->extra);
    }

    #[Test]
    public function clearingOverrideRestoresRequestStackResolution(): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE, 'http-value');

        $stack = new RequestStack();
        $stack->push($request);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $processor = new CorrelationIdProcessor($stack, $security);
        $processor->setOverrideRequestId('worker-value');
        $processor->setOverrideRequestId(null);

        $record = $processor($this->buildRecord());

        self::assertSame('http-value', $record->extra['request_id']);
    }
}
