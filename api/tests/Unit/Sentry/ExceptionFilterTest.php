<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sentry;

use App\Sentry\ExceptionFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[CoversClass(ExceptionFilter::class)]
final class ExceptionFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{0: \Throwable}>
     */
    public static function droppedExceptionsProvider(): iterable
    {
        yield '404' => [new NotFoundHttpException()];
        yield '405' => [new MethodNotAllowedHttpException(['GET'])];
        yield '400' => [new BadRequestHttpException()];
        yield '403' => [new AccessDeniedHttpException()];
        yield '409' => [new ConflictHttpException()];
        yield 'http 499' => [new HttpException(499, 'custom 4xx')];
        yield 'validation failure' => [new ValidationFailedException('payload', new ConstraintViolationList())];
    }

    #[DataProvider('droppedExceptionsProvider')]
    public function testFiltersOutClientErrors(\Throwable $exception): void
    {
        $filter = new ExceptionFilter();
        $event = Event::createEvent(EventId::generate());
        $hint = EventHint::fromArray(['exception' => $exception]);

        self::assertNull($filter($event, $hint));
    }

    /**
     * @return iterable<string, array{0: \Throwable}>
     */
    public static function forwardedExceptionsProvider(): iterable
    {
        yield 'http 500' => [new HttpException(500, 'server explosion')];
        yield 'http 503' => [new HttpException(503, 'service down')];
        yield 'runtime' => [new \RuntimeException('boom')];
        yield 'logic' => [new \LogicException('inconsistent state')];
    }

    #[DataProvider('forwardedExceptionsProvider')]
    public function testForwardsServerErrors(\Throwable $exception): void
    {
        $filter = new ExceptionFilter();
        $event = Event::createEvent(EventId::generate());
        $hint = EventHint::fromArray(['exception' => $exception]);

        $result = $filter($event, $hint);
        self::assertSame($event, $result);
    }

    public function testForwardsEventWithoutExceptionHint(): void
    {
        $filter = new ExceptionFilter();
        $event = Event::createEvent(EventId::generate());

        self::assertSame($event, $filter($event, null));
        self::assertSame($event, $filter($event, EventHint::fromArray([])));
    }
}
