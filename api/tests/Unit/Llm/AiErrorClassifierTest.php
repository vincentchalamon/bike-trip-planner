<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\AiErrorClassifier;
use App\Llm\Exception\AiFailureReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class AiErrorClassifierTest extends TestCase
{
    private AiErrorClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AiErrorClassifier();
    }

    #[Test]
    public function authenticationFailureIsAnInvalidTokenAndNotTransient(): void
    {
        $failure = $this->classifier->classify(new AuthenticationException('nope'), 'gpt-4o-mini');

        self::assertSame(AiFailureReason::INVALID_TOKEN, $failure->getReason());
        self::assertFalse($failure->isTransient());
        self::assertNull($failure->getRetryAfter());
    }

    #[Test]
    public function rateLimitWithRetryAfterIsTransient(): void
    {
        $failure = $this->classifier->classify(new RateLimitExceededException(30), 'gpt-4o-mini');

        self::assertSame(AiFailureReason::RATE_LIMITED, $failure->getReason());
        self::assertTrue($failure->isTransient());
        self::assertSame(30, $failure->getRetryAfter());
    }

    #[Test]
    public function rateLimitWithoutRetryAfterIsAnExhaustedQuota(): void
    {
        $failure = $this->classifier->classify(new RateLimitExceededException(), 'gpt-4o-mini');

        self::assertSame(AiFailureReason::QUOTA_EXCEEDED, $failure->getReason());
        self::assertFalse($failure->isTransient());
    }

    #[Test]
    public function httpUnauthorizedAndForbiddenAreInvalidToken(): void
    {
        self::assertSame(AiFailureReason::INVALID_TOKEN, $this->classifier->classify($this->httpError(401), 'm')->getReason());
        self::assertSame(AiFailureReason::INVALID_TOKEN, $this->classifier->classify($this->httpError(403), 'm')->getReason());
    }

    #[Test]
    public function http429WithRetryAfterHeaderIsRateLimited(): void
    {
        $failure = $this->classifier->classify($this->httpError(429, ['retry-after' => ['12']]), 'm');

        self::assertSame(AiFailureReason::RATE_LIMITED, $failure->getReason());
        self::assertSame(12, $failure->getRetryAfter());
    }

    #[Test]
    public function http429WithoutRetryAfterHeaderIsAnExhaustedQuota(): void
    {
        $failure = $this->classifier->classify($this->httpError(429), 'm');

        self::assertSame(AiFailureReason::QUOTA_EXCEEDED, $failure->getReason());
        self::assertNull($failure->getRetryAfter());
    }

    #[Test]
    public function serverErrorIsTransientUnavailable(): void
    {
        $failure = $this->classifier->classify($this->httpError(503), 'm');

        self::assertSame(AiFailureReason::UNAVAILABLE, $failure->getReason());
        self::assertTrue($failure->isTransient());
    }

    #[Test]
    public function transportErrorIsTransientUnavailable(): void
    {
        $failure = $this->classifier->classify(new TransportException('connection refused'), 'm');

        self::assertSame(AiFailureReason::UNAVAILABLE, $failure->getReason());
        self::assertTrue($failure->isTransient());
    }

    #[Test]
    public function anUnrecognisedPlatformErrorFallsBackToUnavailable(): void
    {
        self::assertSame(AiFailureReason::UNAVAILABLE, $this->classifier->classify(new BadRequestException('bad'), 'm')->getReason());
    }

    #[Test]
    public function preservesTheOriginalExceptionAsPrevious(): void
    {
        $original = new AuthenticationException('boom');

        self::assertSame($original, $this->classifier->classify($original, 'm')->getPrevious());
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function httpError(int $status, array $headers = []): HttpExceptionInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getHeaders')->willReturn($headers);

        // A real throwable: \Throwable::getMessage() cannot be stubbed on a mock.
        return new class ($response, \sprintf('HTTP %d', $status)) extends \RuntimeException implements HttpExceptionInterface {
            public function __construct(private readonly ResponseInterface $response, string $message)
            {
                parent::__construct($message);
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
