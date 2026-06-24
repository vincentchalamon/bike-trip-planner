<?php

declare(strict_types=1);

namespace App\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Guards that every per-user AI provider scoped client (ADR-042) enables
 * `retry_failed`. Gemini (and occasionally the others) returns transient
 * `503 "high demand"` under load on the default model; without retry these
 * surface as a one-shot 503 to the rider. The clients retry 5xx codes only
 * (POST included) with exponential back-off; 429 is explicitly excluded so an
 * exhausted-quota 429 fails fast (ADR-042). Having a {@see RetryableHttpClient}
 * in the stack is what makes the AI endpoints resilient; this asserts the
 * wrapper is present, not the `http_codes` value (that lives in framework.php,
 * validated by Symfony's config parser on kernel boot).
 *
 * A scoped client is a stack of decorators (UriTemplate -> Retryable -> Scoping
 * -> transport), so the assertion walks the chain rather than checking the
 * outermost type.
 */
final class AiProviderClientRetryTest extends KernelTestCase
{
    #[Test]
    #[DataProvider('aiScopedClientIds')]
    public function aiScopedClientEnablesRetry(string $serviceId): void
    {
        self::bootKernel();

        $client = self::getContainer()->get($serviceId);
        self::assertInstanceOf(HttpClientInterface::class, $client);

        self::assertContains(
            RetryableHttpClient::class,
            $this->decoratorChain($client),
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

    /**
     * Walks the HttpClient decorator stack through the conventional private
     * `client` property and returns the class name of each layer.
     *
     * @return list<class-string>
     */
    private function decoratorChain(HttpClientInterface $client): array
    {
        $chain = [];
        $current = $client;

        while (true) {
            $chain[] = $current::class;

            $reflection = new \ReflectionObject($current);
            if (!$reflection->hasProperty('client')) {
                break;
            }

            $inner = $reflection->getProperty('client')->getValue($current);
            if (!$inner instanceof HttpClientInterface) {
                break;
            }

            $current = $inner;
        }

        return $chain;
    }
}
