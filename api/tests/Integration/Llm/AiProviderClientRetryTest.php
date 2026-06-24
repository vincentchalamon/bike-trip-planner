<?php

declare(strict_types=1);

namespace App\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * Guards that every per-user AI provider scoped client (ADR-042) enables
 * `retry_failed`, i.e. is wrapped in a {@see RetryableHttpClient}. Gemini (and
 * occasionally the others) returns transient `503 "high demand"` / `429` under
 * load on the default model; without retry these surface as a one-shot 503 to
 * the rider. Symfony's default retry status codes retry 429 and 503 on every
 * method (POST included), with exponential back-off — so activating the wrapper
 * here is what makes the AI endpoints resilient.
 */
final class AiProviderClientRetryTest extends KernelTestCase
{
    #[Test]
    #[DataProvider('aiScopedClientIds')]
    public function aiScopedClientEnablesRetry(string $serviceId): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            RetryableHttpClient::class,
            self::getContainer()->get($serviceId),
            \sprintf('AI scoped client "%s" must enable retry_failed so transient 429/503 provider failures are retried (ADR-042).', $serviceId),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function aiScopedClientIds(): iterable
    {
        yield 'anthropic' => ['anthropic.client'];
        yield 'openai' => ['openai.client'];
        yield 'gemini' => ['gemini.client'];
    }
}
