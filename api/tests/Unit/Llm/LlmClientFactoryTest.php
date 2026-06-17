<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\AiProvider;
use App\Llm\LlmClientFactory;
use App\Llm\PlatformLlmClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class LlmClientFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{AiProvider, string}>
     */
    public static function providers(): iterable
    {
        // OpenAI's bridge validates the key prefix at construction, so use a
        // format-valid fake token per provider.
        yield 'anthropic' => [AiProvider::ANTHROPIC, 'sk-ant-test'];
        yield 'openai' => [AiProvider::OPENAI, 'sk-test'];
        yield 'gemini' => [AiProvider::GEMINI, 'test-key'];
    }

    #[Test]
    #[DataProvider('providers')]
    public function buildsAPlatformClientForEachProviderWithoutCallingTheNetwork(AiProvider $provider, string $token): void
    {
        // MockHttpClient with no queued responses: construction must not hit the wire.
        $factory = new LlmClientFactory(new MockHttpClient(), new MockHttpClient(), new MockHttpClient());

        self::assertInstanceOf(PlatformLlmClient::class, $factory->create($provider, $token));
    }
}
