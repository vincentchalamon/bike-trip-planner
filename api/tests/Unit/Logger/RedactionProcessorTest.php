<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logger;

use App\Logger\RedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RedactionProcessorTest extends TestCase
{
    #[Test]
    public function redactsTheMessageTheContextAndTheExtra(): void
    {
        $record = new RedactionProcessor()(new LogRecord(
            new \DateTimeImmutable(),
            'request',
            Level::Debug,
            'Sent to rider@example.com',
            ['request_uri' => '/auth/verify/0123abcd', 'token' => 'plain'],
            ['url' => '/s/Ab3-_x9Z'],
        ));

        self::assertSame('Sent to [email]', $record->message);
        self::assertSame(['request_uri' => '/auth/verify/[redacted]', 'token' => '[redacted]'], $record->context);
        self::assertSame(['url' => '/s/[redacted]'], $record->extra);
    }

    #[Test]
    public function aStringableObjectBecomesItsRedactedString(): void
    {
        $token = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'PostAuthenticationToken(user="rider@example.com", roles="ROLE_USER")';
            }
        };

        $record = new RedactionProcessor()(new LogRecord(new \DateTimeImmutable(), 'security', Level::Debug, 'Authenticator successful!', ['authenticated' => $token]));

        self::assertSame(['authenticated' => 'PostAuthenticationToken(user="[email]", roles="ROLE_USER")'], $record->context);
    }

    /** Handlers inspect it: the 404/405 exclusion of fingers_crossed reads its status. */
    #[Test]
    public function anExceptionStaysAnObject(): void
    {
        $exception = new NotFoundHttpException('rider@example.com');

        $record = new RedactionProcessor()(new LogRecord(new \DateTimeImmutable(), 'request', Level::Error, 'Uncaught', ['exception' => $exception]));

        self::assertSame($exception, $record->context['exception']);
    }
}
